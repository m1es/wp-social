<?php
/**
 * Broadcast Controller
 *
 * @package Social
 * @subpackage controllers
 */
final class Social_Controller_Broadcast extends Social_Controller {

	/**
	 * Displays the broadcast options form.
	 * 
	 * @return void
	 */
	public function action_options() {
		$post = get_post($this->request->post('post_ID'));
		if ($post === null or get_post_meta($post->ID, '_social_notify', true) != '1') {
			if ($post === null) {
				wp_redirect(admin_url('index.php'));
			}
			
			return;
		}

		$errors = array();
		$services = $this->social->services();

		$accounts_selected = false;
		if ($this->request->post('social_action') !== null) {
			$service_accounts = $this->request->post('social_accounts');
			$account_content = $this->request->post('social_account_content');

			$account_content_meta = array();
			foreach ($services as $key => $service) {
				if (count($service->accounts())) {
					if (isset($service_accounts[$key])) {
						$accounts_selected = true;

						foreach ($service_accounts[$key] as $account_id) {
							$account_id = explode('|', $account_id);
							if (!isset($account_content[$key][$account_id[0]]) or empty($account_content[$key][$account_id[0]])) {
								if (isset($errors[$key])) {
									$errors[$key] = array();
								}

								$errors[$key][$account_id[0]] = __('Please enter content to be broadcasted.', 'social');
							}
							else {
								$account_content[$key][$account_id[0]] = stripslashes($account_content[$key][$account_id[0]]);
								if (strlen($account_content[$key][$account_id[0]]) > $service->max_broadcast_length()) {
									$errors[$key][$account_id[0]] = sprintf(__('Content must not be longer than %s characters.', 'social'), $service->max_broadcast_length());
								}
								else {
									if (!isset($account_content_meta[$key])) {
										$account_content_meta[$key] = array();
									}

									$account_content_meta[$key][$account_id[0]] = $account_content[$key][$account_id[0]];
								}
							}
						}
					}
					else {
						$pages = $this->request->post('social_facebook_pages');
						foreach ($service->accounts() as $account) {
							if (!empty($pages) and isset($pages[$account->id()])) {
								$accounts_selected = true;

								foreach ($pages[$account->id()] as $page_id) {
									if (!isset($account_content[$key][$page_id]) or empty($account_content[$key][$page_id])) {
										if (isset($errors[$key])) {
											$errors[$key] = array();
										}

										$errors[$key][$page_id] = __('Please enter content to be broadcasted.', 'social');
									}
									else {
										$account_content[$key][$page_id] = stripslashes($account_content[$key][$page_id]);
										if (strlen($account_content[$key][$page_id]) > $service->max_broadcast_length()) {
											$errors[$key][$page_id] = sprintf(__('Content must not be longer than %s characters.', 'social'), $service->max_broadcast_length());
										}
										else {
											if (!isset($account_content_meta[$key])) {
												$account_content_meta[$key] = array();
											}

											$account_content_meta[$key][$page_id] = $account_content[$key][$page_id];
										}
									}
								}
							}
						}
					}
				}
			}

			if (!in_array($post->post_status, array('future', 'pending')) and !$accounts_selected and !isset($errors['rebroadcast'])) {
				$errors['rebroadcast'] = __('Please select at least one account to broadcast to.', 'social');
			}

			if (!count($errors)) {
				$broadcast_accounts = array();
				foreach ($services as $key => $service) {
					if (isset($service_accounts[$key])) {
						$accounts = array();
						foreach ($service_accounts[$key] as $account) {
							$account = explode('|', $account);

							$accounts[$account[0]] = (object) array(
								'id' => $account[0],
								'universal' => isset($account[1]),
							);
						}

						if (!empty($accounts)) {
							$broadcast_accounts[$key] = $accounts;
						}
					}

					// TODO abstract to Facebook plugin.
					$pages = $this->request->post('social_facebook_pages');
					if (is_array($pages)) {
						foreach ($service->accounts() as $account) {
							if (isset($pages[$account->id()])) {
								// TODO This could use some DRY love
								$universal_pages = $account->pages();
								$personal_pages = $account->pages(null, true);
								foreach ($pages[$account->id()] as $page_id) {
									if (!isset($broadcast_accounts[$key])) {
										$broadcast_accounts[$key] = array();
									}

									if (!isset($broadcast_accounts[$key][$page_id])) {
										if (isset($universal_pages[$page_id])) {
											$broadcast_accounts[$key][$page_id] = (object) array(
												'id' => $page_id,
												'name' => $universal_pages[$page_id]->name,
												'universal' => true,
												'page' => true,
											);
										}
									    else if (isset($personal_pages[$page_id])) {
											$broadcast_accounts[$key][$page_id] = (object) array(
												'id' => $page_id,
												'name' => $personal_pages[$page_id]->name,
												'universal' => false,
												'page' => true,
											);
										}
									}
								}
							}
						}
					}
				}

				// Store the content
				update_post_meta($post->ID, '_social_broadcast_content', $account_content_meta);
				update_post_meta($post->ID, '_social_broadcast_accounts', $broadcast_accounts);

				if (!in_array($this->request->post('social_action'), array('Schedule', 'Update'))) {
					$this->action_run($post);
				}

				$location = $this->request->post('location');
				if ($location == null) {
					$location = get_edit_post_link($post->ID, false);
				}
				
				wp_redirect($location);
				exit;
			}
		}

		$step_text = 'Publish';
		if ($this->request->post('social_broadcast') !== null or $this->request->post('social_action') !== null) {
			if (($this->request->post('social_action') === null and $this->request->post('social_broadcast') == 'Edit') or
				$this->request->post('social_action') == 'Update')
			{
				$step = 'Update';
				$step_text = __('Update', 'social');
			}
			else if ($this->request->post('social_action') != 'Publish') {
				$step = 'Broadcast';
				$step_text = __('Broadcast', 'social');
			}
		}
		else if ($post->post_status == 'future' or ($this->request->post('publish') == 'Schedule')) {
			$step = 'Schedule';
			$step_text = __('Schedule', 'social');
		}

// find previous broadcasts
		$broadcasted_ids = get_post_meta($post->ID, '_social_broadcasted_ids', true);
		if (empty($broadcasted_ids)) {
			$broadcasted_ids = array();
		}

// consolidate accounts (merge child accounts into single list)
		$_services = array(); // the data we prep for the view will be in here
		$_defaults = array(
			'name' => '',
			'avatar' => '',
			'field_name_checked' => '',
			'field_name_content' => '',
			'checked' => false,
			'content' => '',
			'broadcasts' => array(),
			'error' => '',
		);
		foreach ($services as $key => $service) {
			$_services[$key] = array();
			if (isset($broadcasted_ids[$key])) {
				$service_broadcasts = $broadcasted_ids[$key];
			}
			else {
				$service_broadcasts = array();
			}
			$accounts = $service->accounts();
			if (count($accounts)) {
				foreach ($accounts as $account) {
					$data = array(
						'name' => $account->name(),
						'avatar' => $account->avatar(),
						'field_name_content' => 'social_account_content['.$key.']['.$account->id().']',
						'field_name_checked' => 'social_account_content['.$key.']',
					);
// assign previous broadcasts
					if (isset($broadcasted_ids[$key]) && isset($broadcasted_ids[$key][$account->id()])) {
						$data['broadcasts'] = $broadcasted_ids[$key][$account->id()];
					}
// assign errors
					if (isset($errors[$key]) && isset($errors[$key][$account->id()])) {
						$data['error'] = $errors[$key][$account->id()];
					}
					$_services[$key][$account->id()] = array_merge($_defaults, $data);
					foreach ($account->child_accounts() as $child_account) {
						$data = array(
							'name' => $child_account->name,
							'avatar' => $service->page_image_url($child_account),
							'field_name_content' => 'social_account_content['.$key.']['.$child_account->id.']',
							'field_name_checked' => 'social_facebook_pages['.$account->id().']',
						);
// assign previous broadcasts
						if (isset($broadcasted_ids[$key]) && isset($broadcasted_ids[$key][$child_account->id])) {
							$data['broadcasts'] = $broadcasted_ids[$key][$child_account->id];
						}
// assign errors
						if (isset($errors[$key]) && isset($errors[$key][$child_account->id])) {
							$data['error'] = $errors[$key][$child_account->id];
						}
						$_services[$key][$child_account->id] = array_merge($_defaults, $data);
					}
				}
			}
		}
		
		$default_accounts = $this->social->default_accounts($post);

		$broadcast_accounts = array();
		$_broadcast_accounts = get_post_meta($post->ID, '_social_broadcast_accounts', true);
		if (!empty($_broadcast_accounts) and count($_broadcast_accounts)) {
			foreach ($_broadcast_accounts as $service => $accounts) {
				foreach ($accounts as $account) {
					if (!isset($broadcast_accounts[$service])) {
						$broadcast_accounts[$service] = array();
					}

					$broadcast_accounts[$service][$account->id] = true;
				}
			}
		}

		$broadcast_content = get_post_meta($post->ID, '_social_broadcast_content', true);
		if (empty($broadcast_content)) {
			$broadcast_content = array();
		}

		foreach ($_services as $key => $accounts) {
			$broadcast_default = $services[$key]->format_content($post, Social::option('broadcast_format'));
// set content format and checked status for each
			foreach ($accounts as $id => &$data) {
//  check for error - populate with previouly posted content
				if (count($errors)) {
					$data['content'] = stripslashes($_POST['social_account_content'][$key][$id]);
					$data['checked'] = (isset($_POST[$data['field_name_checked']]) && in_array($id, $_POST[$data['field_name_checked']]));
				}
// use defaults or saved broadcast info
				else {
					$data['content'] = $broadcast_default;
					$data['checked'] = (isset($default_accounts[$key]) && in_array($id, $default_accounts[$key]));
// check for saved broadcast
					if (isset($broadcast_content[$key]) && isset($broadcast_content[$key][$id])) {
						$data['content'] = $broadcast_content[$key][$id];
					}
					if (count($broadcast_accounts) && isset($broadcast_accounts[$key]) && isset($broadcast_accounts[$key][$id])) {
						$data['checked'] = true;
					}
				}
			}
		}

d($_services);


		echo Social_View::factory('wp-admin/post/broadcast/options', array(
			'errors' => $errors,
			'services' => $services,
			'post' => $post,
			'default_accounts' => $default_accounts,
			'broadcasted_ids' => $broadcasted_ids,
			'broadcast_accounts' => $broadcast_accounts,
			'broadcast_content' => $broadcast_content,
			'step' => $step,
			'step_text' => $step_text,
			'location' => $this->request->post('location'),
		));
		exit;
	}

	/**
	 * Broadcasts a post to the services.
	 *
	 * @param  int|WP_Post  $post_id  post id or post object
	 * @return void
	 */
	public function action_run($post_id = null) {
		if ($post_id === null) {
			$post_id = intval($this->request->query('post_ID'));
		}

		$post = $post_id;
		if (is_int($post_id)) {
			$post = get_post($post_id);
		}

		if ($post === null) {
			Social::log('Failed to broadcast post :post_id.', array(
				'post_id' => $post_id,
			));
			return;
		}

		// Load content to broadcast (accounts, broadcast message, etc)
		$personal_accounts = null;
		$errored_accounts = false;
		$broadcast_accounts = get_post_meta($post->ID, '_social_broadcast_accounts', true);
		$broadcasted_ids = get_post_meta($post->ID, '_social_broadcasted_ids', true);
		if (empty($broadcasted_ids)) {
			$broadcasted_ids = array();
		}

		$account_content = get_post_meta($post->ID, '_social_broadcast_content', true);
		if (empty($account_content)) {
			$account_content = array();
		}

		Social::log('About to start broadcasting.');
		foreach ($broadcast_accounts as $key => $accounts) {
			Social::log('Loading service :service', array('service' => $key));
			$service = $this->social->service($key);
			if ($service) {
				Social::log('Found service :service', array('service' => $key));
				foreach ($accounts as $_account) {
					if ($_account->universal != '1') {
						if ($personal_accounts === null) {
							$personal_accounts = get_user_meta($post->post_author, 'social_accounts', true);
						}

						if (isset($personal_accounts[$key][$_account->id])) {
							$class = 'Social_Service_'.$key.'_Account';
							$account = new $class($personal_accounts[$key][$_account->id]);
						}
						else {
							$account = false;
						}
					}
					else {
						$account = $service->account($_account->id);
					}

					if ($account == false) {
						$account = apply_filters('social_get_broadcast_account', $_account, $post, $service);
					}

					if ($account !== false) {
						// Load the message
						$message = '';
						if (isset($account_content[$key][$_account->id])) {
							$message = $account_content[$key][$_account->id];
						}

						if (!empty($message)) {
							Social::log('Broadcasting to :username, account #:id. (:service)', array(
								'id' => $account->id(),
								'username' => $account->name(),
								'service' => $service->title(),
							));
							
							$response = $service->broadcast($account, $message, array(), $post->ID);
							if ($response !== false) {
								if ($response->limit_reached()) {
									if (!isset($errored_accounts[$key])) {
										$errored_accounts[$key] = array();
									}

									$reason = __('Rate limit reached', 'social');
									$errored_accounts[$key][] = (object) array(
										'account' => $account,
										'reason' => $reason,
										'type' => 'limit_reached',
									);
									Social::log('Broadcasting to :username, account #:id FAILED. Reason: :reason', array(
										'id' => $account->id(),
										'username' => $account->name(),
										'reason' => $reason,
									));
								}
								else if ($response->duplicate_status()) {
									if (!isset($errored_accounts[$key])) {
										$errored_accounts[$key] = array();
									}

									$reason = __('Duplicate status', 'social');
									$errored_accounts[$key][] = (object) array(
										'account' => $account,
										'reason' => $reason,
										'type' => 'duplicate_status',
									);
									Social::log('Broadcasting to :username, account #:id FAILED. Reason: :reason', array(
										'id' => $account->id(),
										'username' => $account->name(),
										'reason' => $reason,
									));
								}
								else if ($response->deauthorized() or $response->deauthorized(true)) {
									if (!isset($errored_accounts[$key])) {
										$errored_accounts[$key] = array();
									}

									$reason = __('Account deauthorized', 'social');
									$errored_accounts[$key][] = (object) array(
										'account' => $account,
										'reason' => $reason,
										'deauthed' => true,
									);
									Social::log('Broadcasting to :username, account #:id FAILED. Reason: :reason', array(
										'id' => $account->id(),
										'username' => $account->name(),
										'reason' => $reason,
									));
								}
								else if ($response->general_error()) {
									if (!isset($errored_accounts[$key])) {
										$errored_accounts[$key] = array();
									}

									$reason = $response->body()->response;
									$errored_accounts[$key][] = (object) array(
										'account' => $account,
										'reason' => $reason,
										'type' => 'general',
									);
									Social::log('Broadcasting to :username, account #:id FAILED. Reason: :reason'."\n\n".'Response:'."\n\n".':response', array(
										'id' => $account->id(),
										'username' => $account->name(),
										'reason' => $reason,
										'response' => print_r($response, true),
									));
								}
								else {
									if (!isset($broadcasted_ids[$key])) {
										$broadcasted_ids[$key] = array();
									}

									$this->social->add_broadcasted_id($post->ID, $key, $response->id(), $response->message($message), $account, $response);
									do_action('social_broadcast_response', $response, $key, $post);

									Social::log('Broadcasting to :username, account #:id COMPLETE. (:service)', array(
										'id' => $account->id(),
										'username' => $account->name(),
										'service' => $service->title(),
									));
								}
							}
						}
					}
				}
			}
		}

		// Errored accounts?
		if ($errored_accounts !== false) {
			$deauthed_accounts = false;
			$_broadcast_accounts = array();
			foreach ($errored_accounts as $key => $accounts) {
				foreach ($accounts as $account) {
					if (isset($account->deauthed)) {
						if (!isset($deauthed_accounts[$key])) {
							$deauthed_accounts[$key] = array();
						}

						$deauthed_accounts[$key][] = $account;
					}

					if (isset($broadcasted_ids[$key]) and isset($broadcast_accounts[$key][$account->id])) {
						if (!isset($_broadcast_accounts[$key])) {
							$_broadcast_accounts[$key] = array();
						}

						$service = $this->social->service($key);
						if ($service !== false) {
							$account = $service->account($account->id);
							if ($account !== false) {
								$_broadcast_accounts[$key][$account->id] = (object) array(
									'id' => $account->id,
									'universal' => $account->universal(),
								);
							}
						}
					}
				}
			}

			update_post_meta($post->ID, '_social_broadcast_error', $errored_accounts);
			if (count($_broadcast_accounts)) {
				update_post_meta($post->ID, '_social_broadcast_accounts', $_broadcast_accounts);
			}
			else {
				delete_post_meta($post->ID, '_social_broadcast_accounts');
			}

			// Retry?
			$retry = Social::option('retry_broadcast');
			if (is_array($retry) and !in_array($post->ID, $retry)) {
				$retry[] = $post->ID;
				Social::option('retry_broadcast', $retry);
			}

			// Deauthed accounts?
			if ($deauthed_accounts !== false or defined('XMLRPC_REQUEST')) {
				if (defined('XMLRPC_REQUEST')) {
					$deauthed_accounts = $errored_accounts;
				}
				$this->send_publish_error($post, $deauthed_accounts);
			}
		}
		else {
			delete_post_meta($post->ID, '_social_broadcast_accounts');
		}
	}

	/**
	 * Sends an email to notify the post author about deauthorized accounts.
	 *
	 * @param  object  $post
	 * @param  array   $accounts
	 * @return void
	 */
	private function send_publish_error($post, $accounts) {
		$author = get_userdata($post->post_author);
		$message = Social_View::factory('wp-admin/post/broadcast/error/email', array(
			'social' => $this->social,
			'accounts' => $accounts,
			'post' => $post,
		));

		$subject = sprintf(__('%s: Failed to broadcast post with Social.', 'social'), get_bloginfo('name'));

		wp_mail($author->user_email, $subject, $message);
	}

} // End Social_Controller_Broadcast
