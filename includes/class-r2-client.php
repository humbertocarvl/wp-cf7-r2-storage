<?php
/**
 * Cliente HTTP para o Cloudflare R2 (API S3-compatível).
 * Implementa AWS Signature Version 4 sem dependências externas.
 *
 * @package CF7_R2_Storage
 */

defined( 'ABSPATH' ) || exit;

class CF7R2_R2_Client {

	/** @var string */
	private string $account_id;

	/** @var string */
	private string $access_key;

	/** @var string */
	private string $secret_key;

	/** @var string */
	private string $bucket;

	/** @var string */
	private string $public_url;

	/** @var string */
	private string $region = 'auto';

	/** @var string */
	private string $service = 's3';

	/** @var int */
	private int $link_expiry;

	/**
	 * @param array $settings Opções do plugin.
	 */
	public function __construct( array $settings ) {
		$this->account_id  = $settings['account_id']        ?? '';
		$this->access_key  = $settings['access_key_id']     ?? '';
		$this->secret_key  = $settings['secret_access_key'] ?? '';
		$this->bucket      = $settings['bucket']            ?? '';
		$this->public_url  = rtrim( $settings['public_url'] ?? '', '/' );
		$this->link_expiry = (int) ( $settings['link_expiry'] ?? 604800 );
	}

	/**
	 * Endpoint base do R2.
	 */
	private function endpoint(): string {
		return sprintf( 'https://%s.r2.cloudflarestorage.com', $this->account_id );
	}

	/**
	 * Faz upload de um arquivo local para o R2 usando cURL com streaming.
	 * Usa UNSIGNED-PAYLOAD para evitar problemas de hash com arquivos binários.
	 *
	 * @param string $file_path    Caminho absoluto do arquivo local.
	 * @param string $object_key  Chave de destino no bucket.
	 * @param string $content_type MIME type do arquivo.
	 * @return string|WP_Error URL pública do objeto ou WP_Error em caso de falha.
	 */
	public function upload( string $file_path, string $object_key, string $content_type ): string|WP_Error {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'cf7r2_file_missing', __( 'Arquivo não encontrado para upload.', 'cf7-r2-storage' ) );
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error( 'cf7r2_file_read', __( 'Não foi possível ler o tamanho do arquivo.', 'cf7-r2-storage' ) );
		}

		$url     = $this->endpoint() . '/' . $this->bucket . '/' . ltrim( $object_key, '/' );
		$headers = $this->sign_request( 'PUT', $url, $content_type );

		// Abre o arquivo em modo binário para streaming com cURL.
		$fh = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			return new WP_Error( 'cf7r2_file_open', __( 'Não foi possível abrir o arquivo para upload.', 'cf7-r2-storage' ) );
		}

		// Monta array de cabeçalhos no formato aceito pelo cURL.
		$curl_headers = [];
		foreach ( $headers as $name => $value ) {
			$curl_headers[] = $name . ': ' . $value;
		}
		$curl_headers[] = 'Content-Type: ' . $content_type;
		$curl_headers[] = 'Content-Length: ' . $file_size;

		$ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_setopt_array( $ch, [ // phpcs:ignore WordPress.WP.AlternativeFunctions
			CURLOPT_CUSTOMREQUEST  => 'PUT',
			CURLOPT_UPLOAD         => true,
			CURLOPT_INFILE         => $fh,
			CURLOPT_INFILESIZE     => $file_size,
			CURLOPT_HTTPHEADER     => $curl_headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_FOLLOWLOCATION => false,
		] );

		$body        = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$curl_err    = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $curl_err ) {
			return new WP_Error( 'cf7r2_curl_error', 'cURL: ' . $curl_err );
		}

		if ( $http_status < 200 || $http_status >= 300 ) {
			$message = $this->parse_s3_error( (string) $body );
			return new WP_Error( 'cf7r2_upload_failed', sprintf( 'R2 HTTP %d: %s', $http_status, $message ) );
		}

		return $this->public_url( $object_key );
	}

	/**
	 * Retorna a URL de acesso ao objeto.
	 *
	 * - Se uma URL pública personalizada estiver configurada, usa ela diretamente
	 *   (adequado para buckets públicos com domínio R2.dev ou customizado).
	 * - Caso contrário, SEMPRE gera uma URL presignada para garantir acesso
	 *   mesmo em buckets privados. link_expiry = 0 usa o padrão de 7 dias.
	 *
	 * @param string $object_key Chave do objeto no bucket.
	 * @return string URL de acesso.
	 */
	public function public_url( string $object_key ): string {
		if ( ! empty( $this->public_url ) ) {
			return $this->public_url . '/' . ltrim( $object_key, '/' );
		}

		// Sem URL pública configurada: gera URL presignada.
		// link_expiry = 0 → usa 7 dias como padrão seguro.
		$expiry = $this->link_expiry > 0 ? $this->link_expiry : 604800;
		return $this->presigned_url( $object_key, $expiry );
	}

	/**
	 * Gera uma URL presignada GET para acesso temporário ao objeto.
	 *
	 * @param string $object_key Chave do objeto.
	 * @param int    $expires    Validade em segundos.
	 * @return string URL presignada.
	 */
	public function presigned_url( string $object_key, int $expires = 604800 ): string {
		$now           = time();
		$date_stamp    = gmdate( 'Ymd', $now );
		$amz_date      = gmdate( 'Ymd\THis\Z', $now );
		$credential_scope = $date_stamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$credential    = $this->access_key . '/' . $credential_scope;

		$host     = $this->account_id . '.r2.cloudflarestorage.com';
		$path     = '/' . $this->bucket . '/' . ltrim( $object_key, '/' );
		$endpoint = 'https://' . $host . $path;

		// Monta os parâmetros de query ordenados alfabeticamente (exigido pelo AWS Sig v4).
		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		];
		ksort( $query_params );

		// Usa RFC 3986 (%20 para espaços, sem +) conforme exigido pelo AWS Sig v4.
		$canonical_qs = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );

		$canonical_headers = 'host:' . $host . "\n";
		$signed_headers    = 'host';

		$canonical_request = implode( "\n", [
			'GET',
			$this->uri_encode_path( $path ),
			$canonical_qs,
			$canonical_headers,
			$signed_headers,
			'UNSIGNED-PAYLOAD',
		] );

		$string_to_sign = implode( "\n", [
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		] );

		$signature = $this->compute_signature( $date_stamp, $string_to_sign );

		return $endpoint . '?' . $canonical_qs . '&X-Amz-Signature=' . rawurlencode( $signature );
	}

	// -------------------------------------------------------------------------
	// AWS Signature Version 4
	// -------------------------------------------------------------------------

	/**
	 * Assina a requisição com AWS Signature V4 usando UNSIGNED-PAYLOAD.
	 *
	 * Assina apenas host, x-amz-content-sha256 e x-amz-date para evitar
	 * discrepâncias causadas por normalização de Content-Type pelo cliente HTTP.
	 *
	 * @param string $method Método HTTP (PUT, GET…).
	 * @param string $url    URL completa da requisição.
	 * @param string $content_type Content-Type (enviado mas não assinado).
	 * @return array Cabeçalhos que devem ser incluídos na requisição.
	 */
	private function sign_request( string $method, string $url, string $content_type = '' ): array {
		$parsed     = wp_parse_url( $url );
		$host       = $parsed['host'];
		$path       = $parsed['path'] ?? '/';
		$now        = time();
		$amz_date   = gmdate( 'Ymd\THis\Z', $now );
		$date_stamp = gmdate( 'Ymd', $now );

		// UNSIGNED-PAYLOAD: evita calcular hash do corpo (suportado pelo R2 sobre HTTPS).
		$payload_token = 'UNSIGNED-PAYLOAD';

		// Cabeçalhos assinados em ordem alfabética.
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers = 'host:' . $host . "\n"
			. 'x-amz-content-sha256:' . $payload_token . "\n"
			. 'x-amz-date:' . $amz_date . "\n";

		$canonical_request = implode( "\n", [
			$method,
			$this->uri_encode_path( $path ),
			'', // query string vazia
			$canonical_headers,
			$signed_headers,
			$payload_token,
		] );

		$credential_scope = $date_stamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign   = implode( "\n", [
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		] );

		$signature = $this->compute_signature( $date_stamp, $string_to_sign );

		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
			$this->access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		return [
			'Authorization'        => $authorization,
			'x-amz-content-sha256' => $payload_token,
			'x-amz-date'           => $amz_date,
		];
	}

	/**
	 * Calcula a assinatura HMAC-SHA256 encadeada.
	 */
	private function compute_signature( string $date_stamp, string $string_to_sign ): string {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $this->service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		return hash_hmac( 'sha256', $string_to_sign, $k_signing );
	}

	/**
	 * Codifica o path URI preservando as barras.
	 */
	private function uri_encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Extrai a mensagem de erro de uma resposta XML do S3/R2.
	 */
	private function parse_s3_error( string $xml ): string {
		if ( empty( $xml ) ) {
			return __( 'Resposta vazia do servidor.', 'cf7-r2-storage' );
		}

		// Desabilita erros externos do XML para evitar injeção via resposta do servidor.
		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml );
		libxml_clear_errors();

		if ( $doc && isset( $doc->Message ) ) {
			return sanitize_text_field( (string) $doc->Message );
		}

		// Fallback seguro: sem exibir XML cru.
		return __( 'Erro desconhecido ao comunicar com o R2.', 'cf7-r2-storage' );
	}
}
