<?php
/**
 * Página de configurações do plugin no painel do WordPress.
 *
 * @package CF7_R2_Storage
 */

defined( 'ABSPATH' ) || exit;

class CF7R2_Admin_Settings {

	/**
	 * Registra os hooks de admin.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	/**
	 * Adiciona item de menu em Configurações.
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'CF7 R2 Storage', 'cf7-r2-storage' ),
			__( 'CF7 R2 Storage', 'cf7-r2-storage' ),
			'manage_options',
			'cf7-r2-storage',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Registra as configurações via Settings API.
	 */
	public static function register_settings(): void {
		register_setting(
			'cf7r2_settings_group',
			CF7R2_OPTION_KEY,
			[ __CLASS__, 'sanitize_options' ]
		);

		add_settings_section(
			'cf7r2_main',
			__( 'Credenciais do Cloudflare R2', 'cf7-r2-storage' ),
			'__return_false',
			'cf7-r2-storage'
		);

		$fields = self::fields();
		foreach ( $fields as $id => $label ) {
			add_settings_field(
				$id,
				$label['label'],
				[ __CLASS__, 'render_field' ],
				'cf7-r2-storage',
				'cf7r2_main',
				[ 'id' => $id, 'type' => $label['type'], 'desc' => $label['desc'] ?? '' ]
			);
		}

		add_settings_section(
			'cf7r2_forms',
			__( 'Formulários habilitados', 'cf7-r2-storage' ),
			[ __CLASS__, 'render_forms_section_intro' ],
			'cf7-r2-storage'
		);

		add_settings_field(
			'allowed_forms',
			__( 'Formulários', 'cf7-r2-storage' ),
			[ __CLASS__, 'render_forms_field' ],
			'cf7-r2-storage',
			'cf7r2_forms'
		);
	}

	/**
	 * Definição dos campos do formulário.
	 */
	private static function fields(): array {
		return [
			'account_id'       => [
				'label' => __( 'Account ID', 'cf7-r2-storage' ),
				'type'  => 'text',
				'desc'  => __( 'ID da conta Cloudflare (encontre em R2 > Visão geral).', 'cf7-r2-storage' ),
			],
			'access_key_id'    => [
				'label' => __( 'Access Key ID', 'cf7-r2-storage' ),
				'type'  => 'text',
				'desc'  => __( 'Chave de acesso gerada em R2 > Gerenciar tokens de API R2.', 'cf7-r2-storage' ),
			],
			'secret_access_key' => [
				'label' => __( 'Secret Access Key', 'cf7-r2-storage' ),
				'type'  => 'password',
				'desc'  => __( 'Chave secreta correspondente ao Access Key ID.', 'cf7-r2-storage' ),
			],
			'bucket'           => [
				'label' => __( 'Nome do Bucket', 'cf7-r2-storage' ),
				'type'  => 'text',
				'desc'  => __( 'Nome exato do bucket R2 de destino.', 'cf7-r2-storage' ),
			],
			'public_url'       => [
				'label' => __( 'URL pública do bucket', 'cf7-r2-storage' ),
				'type'  => 'url',
				'desc'  => __( 'Ex.: https://pub-xxx.r2.dev ou seu domínio personalizado. Deixe vazio para usar o endpoint padrão.', 'cf7-r2-storage' ),
			],
			'path_prefix'      => [
				'label' => __( 'Prefixo de pasta (opcional)', 'cf7-r2-storage' ),
				'type'  => 'text',
				'desc'  => __( 'Ex.: uploads/cf7 — os arquivos serão salvos dentro dessa pasta no bucket.', 'cf7-r2-storage' ),
			],
			'link_expiry'      => [
				'label' => __( 'Validade do link presignado (segundos)', 'cf7-r2-storage' ),
				'type'  => 'number',
				'desc'  => __( 'Tempo de validade das URLs de download. Padrão: 604800 (7 dias). Use 0 para manter o padrão de 7 dias. Para links permanentes sem expiração, configure uma URL pública R2 no campo acima (domínio r2.dev ou domínio personalizado com bucket público).', 'cf7-r2-storage' ),
			],
		];
	}

	/**
	 * Sanitiza as opções antes de salvar.
	 */
	public static function sanitize_options( $input ): array {
		$clean = [];

		$clean['account_id']        = sanitize_text_field( $input['account_id'] ?? '' );
		$clean['access_key_id']     = sanitize_text_field( $input['access_key_id'] ?? '' );
		$clean['bucket']            = sanitize_text_field( $input['bucket'] ?? '' );
		$clean['public_url']        = esc_url_raw( $input['public_url'] ?? '' );
		$clean['path_prefix']       = trim( sanitize_text_field( $input['path_prefix'] ?? '' ), '/' );
		$clean['link_expiry']       = absint( $input['link_expiry'] ?? 604800 );

		// IDs dos formulários CF7 habilitados (array de inteiros).
		$raw_forms = isset( $input['allowed_forms'] ) ? (array) $input['allowed_forms'] : [];
		$clean['allowed_forms'] = array_values( array_map( 'absint', array_filter( $raw_forms ) ) );

		// Não sobrescreve a chave secreta se o campo vier vazio (evita apagar acidentalmente).
		$existing = get_option( CF7R2_OPTION_KEY, [] );
		if ( ! empty( $input['secret_access_key'] ) ) {
			$clean['secret_access_key'] = sanitize_text_field( $input['secret_access_key'] );
		} else {
			$clean['secret_access_key'] = $existing['secret_access_key'] ?? '';
		}

		return $clean;
	}

	/**
	 * Texto introdutório da seção de formulários.
	 */
	public static function render_forms_section_intro(): void {
		echo '<p>' . esc_html__( 'Escolha quais formulários terão seus anexos enviados ao R2. Se nenhum for marcado, todos os formulários serão interceptados.', 'cf7-r2-storage' ) . '</p>';
	}

	/**
	 * Renderiza os checkboxes de seleção de formulários CF7.
	 */
	public static function render_forms_field(): void {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			echo '<p class="description">' . esc_html__( 'Contact Form 7 não está ativo.', 'cf7-r2-storage' ) . '</p>';
			return;
		}

		$options       = get_option( CF7R2_OPTION_KEY, [] );
		$allowed       = array_map( 'strval', $options['allowed_forms'] ?? [] );
		$forms         = WPCF7_ContactForm::find();

		if ( empty( $forms ) ) {
			echo '<p class="description">' . esc_html__( 'Nenhum formulário CF7 encontrado.', 'cf7-r2-storage' ) . '</p>';
			return;
		}

		echo '<fieldset style="max-height:220px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;background:#fff;">';
		foreach ( $forms as $form ) {
			$form_id = (string) $form->id();
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[allowed_forms][]" value="%s" %s /> %s</label>',
				esc_attr( CF7R2_OPTION_KEY ),
				esc_attr( $form_id ),
				checked( in_array( $form_id, $allowed, true ), true, false ),
				esc_html( sprintf( '#%s — %s', $form_id, $form->title() ) )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Renderiza um campo individual.
	 */
	public static function render_field( array $args ): void {
		$options = get_option( CF7R2_OPTION_KEY, [] );
		$id      = esc_attr( $args['id'] );
		$type    = esc_attr( $args['type'] );
		$value   = $options[ $args['id'] ] ?? '';

		// Nunca exibe o valor da chave secreta no campo (segurança).
		if ( 'password' === $type ) {
			$display = '';
			$placeholder = ! empty( $value ) ? __( '(já configurado — deixe vazio para manter)', 'cf7-r2-storage' ) : '';
		} else {
			$display     = esc_attr( $value );
			$placeholder = '';
		}

		printf(
			'<input type="%s" id="%s" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" autocomplete="off" />',
			$type,
			$id,
			esc_attr( CF7R2_OPTION_KEY ),
			$id,
			$display,
			$placeholder
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $args['desc'] ) );
		}
	}

	/**
	 * Renderiza a página de configurações.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CF7 R2 Storage — Configurações', 'cf7-r2-storage' ); ?></h1>
			<p>
				<?php esc_html_e( 'Configure as credenciais do Cloudflare R2. Os anexos enviados via Contact Form 7 serão enviados ao bucket e os links de download serão incluídos no e-mail.', 'cf7-r2-storage' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'cf7r2_settings_group' );
				do_settings_sections( 'cf7-r2-storage' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Testar conexão', 'cf7-r2-storage' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=cf7-r2-storage&cf7r2_test=1' ), 'cf7r2_test' ) ); ?>" class="button">
					<?php esc_html_e( 'Testar credenciais R2', 'cf7-r2-storage' ); ?>
				</a>
			</p>
			<?php self::maybe_show_test_result(); ?>
		</div>
		<?php
	}

	/**
	 * Executa e exibe o resultado do teste de conexão.
	 */
	private static function maybe_show_test_result(): void {
		if ( empty( $_GET['cf7r2_test'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'cf7r2_test' ) ) {
			return;
		}

		$settings = get_option( CF7R2_OPTION_KEY, [] );

		if ( empty( $settings['account_id'] ) || empty( $settings['access_key_id'] ) || empty( $settings['secret_access_key'] ) || empty( $settings['bucket'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Preencha todas as credenciais antes de testar.', 'cf7-r2-storage' ) . '</p></div>';
			return;
		}

		// Cria um arquivo temporário de teste.
		$tmp = wp_tempnam( 'cf7r2-test' );
		file_put_contents( $tmp, 'cf7-r2-storage connection test - ' . time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$client = new CF7R2_R2_Client( $settings );
		$key    = ltrim( ( $settings['path_prefix'] ?? '' ) . '/cf7r2-connection-test.txt', '/' );
		$result = $client->upload( $tmp, $key, 'text/plain' );

		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_wp_error( $result ) ) {
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'Falha na conexão:', 'cf7-r2-storage' ),
				esc_html( $result->get_error_message() )
			);
		} else {
			printf(
				'<div class="notice notice-success"><p>%s <code>%s</code></p></div>',
				esc_html__( 'Conexão bem-sucedida! Arquivo de teste enviado:', 'cf7-r2-storage' ),
				esc_html( $result )
			);
		}
	}
}
