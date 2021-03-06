<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

namespace Fragen\GitHub_Updater;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Bitbucket_API
 *
 * Get remote data from a Bitbucket repo.
 *
 * @package Fragen\GitHub_Updater
 * @author  Andy Fragen
 */
class Bitbucket_API extends API {

	/**
	 * Constructor.
	 *
	 * @param object $type
	 */
	public function __construct( $type ) {
		$this->type     = $type;
		$this->response = $this->get_repo_cache();

		$this->load_hooks();

		if ( ! isset( self::$options['bitbucket_consumer_key'] ) ) {
			self::$options['bitbucket_consumer_key'] = null;
		}
		if ( ! isset( self::$options['bitbucket_consumer_secret'] ) ) {
			self::$options['bitbucket_consumer_secret'] = null;
		}
		if ( ! isset( self::$options['bitbucket_auth_token'] ) ) {
			self::$options['bitbucket_auth_token'] = null;
		}
		add_site_option( 'github_updater', self::$options );
	}

	/**
	 * Load hooks for Bitbucket authentication headers.
	 */
	public function load_hooks() {
		add_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ), 5, 2 );
		add_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ), 15, 2 );
	}

	/**
	 * Remove hooks for Bitbucket authentication headers.
	 */
	public function remove_hooks() {
		remove_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ) );
		remove_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ) );
		remove_filter( 'http_request_args', array( &$this, 'ajax_maybe_authenticate_http' ) );
	}

	/**
	 * Read the remote file and parse headers.
	 *
	 * @param $file
	 *
	 * @return bool
	 */
	public function get_remote_info( $file ) {
		$response = isset( $this->response[ $file ] ) ? $this->response[ $file ] : false;

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/:branch/' . $file );

			if ( $response ) {
				$contents = $response->data;
				$response = $this->get_file_headers( $contents, $this->type->type );
				$this->set_repo_cache( $file, $response );
			}
		}

		if ( $this->validate_response( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$response['dot_org'] = $this->get_dot_org_data();
		$this->set_file_info( $response );

		return true;
	}

	/**
	 * Get the remote info to for tags.
	 *
	 * @return bool
	 */
	public function get_remote_tag() {
		$repo_type = $this->return_repo_type();
		$response  = isset( $this->response['tags'] ) ? $this->response['tags'] : false;

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/tags' );
			$arr_resp = (array) $response;

			if ( ! $response || ! $arr_resp ) {
				$response          = new \stdClass();
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$response = $this->parse_tag_response( $response );
				$this->set_repo_cache( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->parse_tags( $response, $repo_type );

		return true;
	}

	/**
	 * Read the remote CHANGES.md file
	 *
	 * @param $changes
	 *
	 * @return bool
	 */
	public function get_remote_changes( $changes ) {
		$response = isset( $this->response['changes'] ) ? $this->response['changes'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = array();
			$content  = $this->get_local_info( $this->type, $changes );
			if ( $content ) {
				$response['changes'] = $content;
				$this->set_repo_cache( 'changes', $response );
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/:branch/' . $changes );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No changelog found';
			}

			if ( $response ) {
				$response = $this->parse_changelog_response( $response );
				$this->set_repo_cache( 'changes', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$parser    = new \Parsedown;
		$changelog = $parser->text( $response['changes'] );

		$this->type->sections['changelog'] = $changelog;

		return true;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @return bool
	 */
	public function get_remote_readme() {
		if ( ! file_exists( $this->type->local_path . 'readme.txt' ) &&
		     ! file_exists( $this->type->local_path_extended . 'readme.txt' )
		) {
			return false;
		}

		$response = isset( $this->response['readme'] ) ? $this->response['readme'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = new \stdClass();
			$content  = $this->get_local_info( $this->type, 'readme.txt' );
			if ( $content ) {
				$response->data = $content;
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/:branch/' . 'readme.txt' );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No readme found';
			}

		}

		if ( $response && isset( $response->data ) ) {
			$file     = $response->data;
			$parser   = new Readme_Parser( $file );
			$response = $parser->parse_data();
			$this->set_repo_cache( 'readme', $response );
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->set_readme_info( $response );

		return true;
	}

	/**
	 * Read the repository meta from API
	 *
	 * @return bool
	 */
	public function get_repo_meta() {
		$response = isset( $this->response['meta'] ) ? $this->response['meta'] : false;

		if ( ! $response ) {
			$response = $this->api( '/2.0/repositories/:owner/:repo' );

			if ( $response ) {
				$response = $this->parse_meta_response( $response );
				$this->set_repo_cache( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->add_meta_repo_object();

		return true;
	}

	/**
	 * Create array of branches and download links as array.
	 *
	 * @return bool
	 */
	public function get_remote_branches() {
		$branches = array();
		$response = isset( $this->response['branches'] ) ? $this->response['branches'] : false;

		if ( $this->exit_no_update( $response, true ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/branches' );

			if ( $response ) {
				foreach ( $response as $branch => $api_response ) {
					$branches[ $branch ] = $this->construct_download_link( false, $branch );
				}
				$this->type->branches = $branches;
				$this->set_repo_cache( 'branches', $branches );

				return true;
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Construct $this->type->download_link using Bitbucket API
	 *
	 * @param boolean $rollback      for theme rollback
	 * @param boolean $branch_switch for direct branch changing
	 *
	 * @return string $endpoint
	 */
	public function construct_download_link( $rollback = false, $branch_switch = false ) {
		$download_link_base = implode( '/', array(
			'https://bitbucket.org',
			$this->type->owner,
			$this->type->repo,
			'get/',
		) );
		$endpoint           = '';

		if ( $this->type->release_asset && '0.0.0' !== $this->type->newest_tag ) {
			return $this->make_release_asset_download_link();
		}

		/*
		 * Check for rollback.
		 */
		if ( ! empty( $_GET['rollback'] ) &&
		     ( isset( $_GET['action'] ) && 'upgrade-theme' === $_GET['action'] ) &&
		     ( isset( $_GET['theme'] ) && $this->type->repo === $_GET['theme'] )
		) {
			$endpoint .= $rollback . '.zip';

			// for users wanting to update against branch other than master or not using tags, else use newest_tag
		} elseif ( 'master' != $this->type->branch || empty( $this->type->tags ) ) {
			$endpoint .= $this->type->branch . '.zip';
		} else {
			$endpoint .= $this->type->newest_tag . '.zip';
		}

		/*
		 * Create endpoint for branch switching.
		 */
		if ( $branch_switch ) {
			$endpoint = $branch_switch . '.zip';
		}

		return $download_link_base . $endpoint;
	}

	/**
	 * Get/process Language Packs.
	 *
	 * @TODO Bitbucket Enterprise
	 *
	 * @param array $headers Array of headers of Language Pack.
	 *
	 * @return bool When invalid response.
	 */
	public function get_language_pack( $headers ) {
		$response = ! empty( $this->response['languages'] ) ? $this->response['languages'] : false;
		$type     = explode( '_', $this->type->type );

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/' . $headers['owner'] . '/' . $headers['repo'] . '/src/master/language-pack.json' );

			if ( $this->validate_response( $response ) ) {
				return false;
			}

			if ( $response ) {
				$response = json_decode( $response->data );

				foreach ( $response as $locale ) {
					$package = array( 'https://bitbucket.org', $headers['owner'], $headers['repo'], 'raw/master' );
					$package = implode( '/', $package ) . $locale->package;

					$response->{$locale->language}->package = $package;
					$response->{$locale->language}->type    = $type[1];
					$response->{$locale->language}->version = $this->type->remote_version;
				}

				$this->set_repo_cache( 'languages', $response );
			}
		}
		$this->type->language_packs = $response;
	}

	/**
	 * Add Basic Authentication $args to http_request_args filter hook
	 * for private Bitbucket repositories only.
	 *
	 * @param  $args
	 * @param  $url
	 *
	 * @return mixed $args
	 */
	public function maybe_authenticate_http( $args, $url ) {
		if ( ! isset( $this->type ) || false === stristr( $url, 'bitbucket' ) ) {
			return $args;
		}

		$bitbucket_private         = false;
		$bitbucket_private_install = false;

		/*
		 * Check whether attempting to update private Bitbucket repo.
		 */
		if ( isset( $this->type->repo ) &&
		     ! empty( parent::$options[ $this->type->repo ] ) &&
		     false !== strpos( $url, $this->type->repo )
		) {
			$bitbucket_private = true;
		}

		/*
		 * Check whether attempting to install private Bitbucket repo
		 * and abort if Bitbucket user/pass not set.
		 */
		if ( isset( $_POST['option_page'], $_POST['is_private'] ) &&
			 'github_updater_install' === $_POST['option_page'] &&
			 'bitbucket' === $_POST['github_updater_api'] &&
			 ( ! empty( self::$options['bitbucket_auth_token'] ) )
		) {
			$bitbucket_private_install = true;
		}

		if ( $bitbucket_private || $bitbucket_private_install ) {
			$grant_type = isset( self::$options['bitbucket_refresh_token'] ) ? 'refresh_token' : 'authorization_code';
			$post_fields = array(
				"grant_type" => $grant_type, 
				( ( $grant_type == 'authorization_code' ) ? 'code' : $grant_type ) => 
				( ( $grant_type == 'authorization_code' ) ? self::$options['bitbucket_auth_token'] : self::$options['bitbucket_refresh_token'] )
			);

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, "https://bitbucket.org/site/oauth2/access_token" );
			curl_setopt( $ch, CURLOPT_USERPWD, self::$options['bitbucket_consumer_key'] . ":" . self::$options['bitbucket_consumer_secret'] );
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post_fields ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			$data = curl_exec( $ch );
			curl_close( $ch );

			$data = json_decode($data);
			if(!$data->error) {
				self::$options['bitbucket_refresh_token'] = $data->refresh_token;
				update_site_option( 'github_updater', self::$options );

				$args['headers']['Authorization'] = 'Bearer ' . $data->access_token;
			}
		}

		return $args;
	}

	/**
	 * Removes Basic Authentication header for Bitbucket Release Assets.
	 * Storage in AmazonS3 buckets, uses Query String Request Authentication Alternative.
	 *
	 * @link http://docs.aws.amazon.com/AmazonS3/latest/dev/RESTAuthentication.html#RESTAuthenticationQueryStringAuth
	 *
	 * @param $args
	 * @param $url
	 *
	 * @return mixed
	 */
	public function http_release_asset_auth( $args, $url ) {
		$arrURL = parse_url( $url );
		if ( 'bbuseruploads.s3.amazonaws.com' === $arrURL['host'] ) {
			unset( $args['headers']['Authorization'] );
		}

		return $args;
	}

	/**
	 * Added due to abstract class designation, not used for Bitbucket.
	 *
	 * @param $git
	 * @param $endpoint
	 */
	protected function add_endpoints( $git, $endpoint ) {
	}

	/**
	 * Add Basic Authentication $args to http_request_args filter hook
	 * for private Bitbucket repositories only during AJAX.
	 *
	 * @param $args
	 * @param $url
	 *
	 * @return mixed
	 */
	public function ajax_maybe_authenticate_http( $args, $url ) {
		global $wp_current_filter;

		$ajax_update    = array( 'wp_ajax_update-plugin', 'wp_ajax_update-theme' );
		$is_ajax_update = array_intersect( $ajax_update, $wp_current_filter );

		if ( ! empty( $is_ajax_update ) ) {
			$this->load_options();
		}

		if ( parent::is_doing_ajax() && ! parent::is_heartbeat() &&
			 ( isset( $_POST['slug'] ) && array_key_exists( $_POST['slug'], self::$options ) &&
			   1 == self::$options[ $_POST['slug'] ] &&
			   false !== stristr( $url, $_POST['slug'] ) )
		) {
			$grant_type = isset(self::$options['bitbucket_refresh_token']) ? 'refresh_token' : 'authorization_code';
			$post_fields = array(
				"grant_type" => $grant_type, 
				( ( $grant_type == 'authorization_code' ) ? 'code' : $grant_type ) => 
				( ( $grant_type == 'authorization_code' ) ? self::$options['bitbucket_auth_token'] : self::$options['bitbucket_refresh_token'] )
			);

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, "https://bitbucket.org/site/oauth2/access_token" );
			curl_setopt( $ch, CURLOPT_USERPWD, self::$options['bitbucket_consumer_key'] . ":" . self::$options['bitbucket_consumer_secret'] );
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post_fields ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			$data = curl_exec( $ch );
			curl_close( $ch );

			$data = json_decode($data);
			if(!$data->error) {
				self::$options['bitbucket_refresh_token'] = $data->refresh_token;
				update_site_option( 'github_updater', self::$options );

				$args['headers']['Authorization'] = 'Bearer ' . $data->access_token;
			}
		}

		return $args;
	}

	/**
	 * Parse API response call and return only array of tag numbers.
	 *
	 * @param object $response Response from API call.
	 *
	 * @return array|object Array of tag numbers, object is error.
	 */
	protected function parse_tag_response( $response ) {
		if ( isset( $response->message ) ) {
			return $response;
		}

		return array_keys( (array) $response );
	}

	/**
	 * Parse API response and return array of meta variables.
	 *
	 * @param object $response Response from API call.
	 *
	 * @return array $arr Array of meta variables.
	 */
	protected function parse_meta_response( $response ) {
		$arr      = array();
		$response = array( $response );

		array_filter( $response, function( $e ) use ( &$arr ) {
			$arr['private']      = $e->is_private;
			$arr['last_updated'] = $e->updated_on;
			$arr['watchers']     = 0;
			$arr['forks']        = 0;
			$arr['open_issues']  = 0;
		} );

		return $arr;
	}

	/**
	 * Parse API response and return array with changelog in base64.
	 *
	 * @param object $response Response from API call.
	 *
	 * @return array|object $arr Array of changes in base64, object if error.
	 */
	protected function parse_changelog_response( $response ) {
		if ( isset( $response->message ) ) {
			return $response;
		}

		$arr      = array();
		$response = array( $response );

		array_filter( $response, function( $e ) use ( &$arr ) {
			$arr['changes'] = $e->data;
		} );

		return $arr;
	}

}
