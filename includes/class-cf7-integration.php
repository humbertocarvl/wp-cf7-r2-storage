<?php
/**
 * Integração com o Contact Form 7.
 * Intercepta o envio, faz upload dos anexos ao R2 e injeta os links no e-mail.
 *
 * @package CF7_R2_Storage
 */

defined( 'ABSPATH' ) || exit;

class CF7R2_CF7_Integration {

	/**
	 * Cache das URLs R2 geradas na submissão atual.
	 * Chave: nome do campo CF7. Valor: array de URLs.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $r2_urls = [];

	/**
	 * Caminhos locais dos arquivos temporários a remover após o CFDB7 processar.
	 *
	 * @var list<string>
	 */
	private static array $paths_to_delete = [];

	/**
	 * Registra os hooks do CF7 e do CFDB7.
	 */
	public static function init(): void {
		// Prioridade 5: upload para R2 antes do CFDB7 (prioridade 10).
		add_action( 'wpcf7_before_send_mail', [ __CLASS__, 'handle_uploads' ], 5, 3 );

		// Prioridade 20: limpa os temporários DEPOIS do CFDB7 processar.
		add_action( 'wpcf7_before_send_mail', [ __CLASS__, 'delete_temp_files' ], 20, 3 );

		// Impede que o CFDB7 copie os arquivos para cfdb7_uploads no servidor.
		add_filter( 'cfdb7_before_file_copy', '__return_empty_array' );

		// Substitui os campos de arquivo do CFDB7 pelas URLs do R2.
		add_filter( 'cfdb7_before_save_data', [ __CLASS__, 'inject_r2_urls_into_cfdb7' ] );

		// Corrige os links no painel do CFDB7 (JS no admin_footer).
		add_action( 'admin_footer', [ __CLASS__, 'admin_footer_fix_links' ] );
	}

	/**
	 * Processa os anexos da submissão:
	 *  1. Faz upload de cada arquivo ao R2 e armazena as URLs.
	 *  2. Injeta os links de download no corpo do e-mail.
	 *  (Os arquivos temporários são deletados em delete_temp_files, prioridade 20.)
	 *
	 * @param WPCF7_ContactForm $contact_form Objeto do formulário.
	 * @param bool              $abort        Referência: defina true para abortar envio.
	 * @param WPCF7_Submission  $submission   Objeto da submissão atual.
	 */
	public static function handle_uploads(
		WPCF7_ContactForm $contact_form,
		bool &$abort,
		WPCF7_Submission $submission
	): void {
		// Limpa o estado de submissões anteriores (caso raro de múltiplas submissões no mesmo request).
		self::$r2_urls         = [];
		self::$paths_to_delete = [];

		$settings = get_option( CF7R2_OPTION_KEY, [] );

		if (
			empty( $settings['account_id'] ) ||
			empty( $settings['access_key_id'] ) ||
			empty( $settings['secret_access_key'] ) ||
			empty( $settings['bucket'] )
		) {
			return;
		}

		// Se houver formul\u00e1rios selecionados, ignora os que n\u00e3o est\u00e3o na lista.
		$allowed_forms = $settings['allowed_forms'] ?? [];
		if ( ! empty( $allowed_forms ) ) {
			$allowed_forms = array_map( 'strval', $allowed_forms );
			if ( ! in_array( (string) $contact_form->id(), $allowed_forms, true ) ) {
				return;
			}
		}

		$uploaded_files = $submission->uploaded_files();
		if ( empty( $uploaded_files ) ) {
			return;
		}

		$client = new CF7R2_R2_Client( $settings );
		$prefix = trim( $settings['path_prefix'] ?? '', '/' );

		foreach ( $uploaded_files as $field_name => $paths ) {
			foreach ( (array) $paths as $local_path ) {
				if ( empty( $local_path ) || ! file_exists( $local_path ) ) {
					continue;
				}

				$object_key = self::build_object_key( $prefix, $local_path );
				$mime       = mime_content_type( $local_path ) ?: 'application/octet-stream';
				$result     = $client->upload( $local_path, $object_key, $mime );

				if ( is_wp_error( $result ) ) {
					error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
						'CF7 R2 Storage: falha ao enviar "%s" — %s',
						$field_name,
						$result->get_error_message()
					) );
					continue;
				}

				self::$r2_urls[ $field_name ][]  = $result;
				self::$paths_to_delete[]          = $local_path;
			}
		}

		if ( empty( self::$r2_urls ) ) {
			return;
		}

		self::inject_links_into_mail( $contact_form, self::$r2_urls );
	}

	/**
	 * Deleta os arquivos temporários locais DEPOIS que o CFDB7 (prioridade 10) já processou.
	 * Chamado na prioridade 20 do hook wpcf7_before_send_mail.
	 */
	public static function delete_temp_files(
		WPCF7_ContactForm $contact_form,
		bool &$abort,
		WPCF7_Submission $submission
	): void {
		foreach ( self::$paths_to_delete as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				// Remove o diretório temporário pai se estiver vazio.
				$dir = dirname( $path );
				if ( is_dir( $dir ) && count( (array) @scandir( $dir ) ) <= 2 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
		self::$paths_to_delete = [];
	}

	/**
	 * Substitui os valores dos campos de arquivo do CFDB7 pelas URLs do R2.
	 * Chamado pelo filtro cfdb7_before_save_data.
	 *
	 * O CFDB7 armazena arquivos com chave "{campo}cfdb7_file". Quando
	 * cfdb7_before_file_copy retorna array vazio, o valor fica "". Aqui
	 * injetamos a URL do R2 nesses campos.
	 *
	 * @param array $form_data Dados que o CFDB7 vai salvar no banco.
	 * @return array Dados com URLs R2 no lugar dos nomes de arquivo locais.
	 */
	public static function inject_r2_urls_into_cfdb7( array $form_data ): array {
		if ( empty( self::$r2_urls ) ) {
			return $form_data;
		}

		foreach ( $form_data as $key => $value ) {
			// Identifica chaves de arquivo do CFDB7: terminam em "cfdb7_file".
			if ( substr( $key, -10 ) !== 'cfdb7_file' ) {
				continue;
			}

			$field_name = substr( $key, 0, -10 ); // remove o sufixo "cfdb7_file"

			if ( ! isset( self::$r2_urls[ $field_name ] ) ) {
				continue;
			}

			// Armazena a primeira URL (CFDB7 suporta um arquivo por campo).
			// Prefixo "cf7r2|" permite identificar URLs R2 no display.
			$form_data[ $key ] = 'cf7r2|' . self::$r2_urls[ $field_name ][0];
		}

		return $form_data;
	}

	/**
	 * Injeta JavaScript no admin_footer para corrigir os links do CFDB7
	 * que ficam com o formato "cfdb7_uploads/cf7r2|https://...".
	 * Roda apenas nas páginas do CFDB7.
	 */
	public static function admin_footer_fix_links(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Páginas do CFDB7 usam menu slug "cfdb7-list.php".
		if ( false === strpos( $screen->id ?? '', 'cfdb7' ) ) {
			return;
		}
		?>
		<script>
		(function () {
			'use strict';
			var prefix = 'cf7r2|';
			document.querySelectorAll('a[href]').forEach(function (a) {
				var href = decodeURIComponent(a.getAttribute('href') || '');
				// Verifica se a href contém o marcador do R2
				var idx = href.indexOf(prefix);
				if (idx === -1) return;
				// Extrai a URL real (tudo após o marcador)
				var r2url = href.slice(idx + prefix.length);
				a.setAttribute('href', r2url);
				a.setAttribute('target', '_blank');
				a.setAttribute('rel', 'noopener noreferrer');
				// Exibe o nome do arquivo em vez da URL completa
				var parts = r2url.split('/');
				var filename = parts[parts.length - 1].split('?')[0];
				if (filename) {
					a.textContent = decodeURIComponent(filename.replace(/^\w{8}_/, ''));
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Modifica os templates de mail do formulário adicionando os links R2.
	 * O bloco de links é adicionado ao final do corpo do e-mail.
	 *
	 * @param WPCF7_ContactForm $contact_form Formulário atual.
	 * @param array             $r2_links     Mapa field_name => [url, ...].
	 */
	private static function inject_links_into_mail(
		WPCF7_ContactForm $contact_form,
		array $r2_links
	): void {
		// Constrói bloco de texto com os links.
		$block  = "\n\n" . __( '--- Anexos (armazenados no Cloudflare R2) ---', 'cf7-r2-storage' ) . "\n";
		foreach ( $r2_links as $field => $urls ) {
			foreach ( $urls as $url ) {
				$block .= sprintf( "[%s] %s\n", esc_html( $field ), esc_url( $url ) );
			}
		}

		// Aplica nos dois templates (mail e mail_2).
		foreach ( [ 'mail', 'mail_2' ] as $mail_slot ) {
			$mail = $contact_form->prop( $mail_slot );
			if ( empty( $mail ) || empty( $mail['active'] ) && 'mail' !== $mail_slot ) {
				// mail_2 só está ativo se 'active' == true; mail principal sempre está ativo.
				if ( 'mail' !== $mail_slot ) {
					continue;
				}
			}

			if ( ! is_array( $mail ) ) {
				continue;
			}

			// Adiciona o bloco de links ao corpo.
			$mail['body'] = ( $mail['body'] ?? '' ) . $block;

			// Remove os arquivos da lista de attachments do mail (evita tentativas de attach local).
			$mail['attachments'] = '';

			$contact_form->set_properties( [ $mail_slot => $mail ] );
		}
	}

	/**
	 * Gera a chave do objeto no bucket com data para evitar colisões.
	 *
	 * @param string $prefix     Prefixo de pasta configurado.
	 * @param string $local_path Caminho local do arquivo.
	 * @return string Chave do objeto.
	 */
	private static function build_object_key( string $prefix, string $local_path ): string {
		$date     = gmdate( 'Y/m/d' );
		$basename = wp_basename( $local_path );

		// Sanitiza o nome do arquivo: remove caracteres perigosos.
		$basename = preg_replace( '/[^a-zA-Z0-9.\-_]/', '_', $basename );
		$basename = substr( $basename, 0, 200 ); // limita o tamanho

		$unique = substr( wp_generate_uuid4(), 0, 8 );

		$parts = array_filter( [ $prefix, $date, $unique . '_' . $basename ] );
		return implode( '/', $parts );
	}
}
