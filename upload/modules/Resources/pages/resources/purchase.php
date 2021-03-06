<?php
/*
 *	Made by Samerton
 *  https://github.com/NamelessMC/Nameless/
 *  NamelessMC version 2.0.0-pr5
 *
 *  License: MIT
 *
 *  Resources - purchase
 */
// Always define page name
define('PAGE', 'resources');
define('RESOURCE_PAGE', 'purchase');

if(!$user->isLoggedIn()){
	Redirect::to(URL::build('/resources'));
	die();
}

// Get resource
$rid = explode('/', $route);
$rid = $rid[count($rid) - 1];

if(!isset($rid[count($rid) - 1])){
	Redirect::to(URL::build('/resources'));
	die();
}

$rid = explode('-', $rid);
if(!is_numeric($rid[0])){
	Redirect::to(URL::build('/resources'));
	die();
}
$rid = $rid[0];

$resource = $queries->getWhere('resources', array('id', '=', $rid));
if(!count($resource)){
	Redirect::to(URL::build('/resources'));
	die();
}
$resource = $resource[0];

if($user->data()->id == $resource->creator_id || $resource->type == 0){
	// Can't purchase own resource
	Redirect::to(URL::build('/resources'));
	die();
}

// Check permissions
$permissions = DB::getInstance()->query('SELECT `view`, download FROM nl2_resources_categories_permissions WHERE group_id = ? AND category_id = ?', array($user->data()->group_id, $resource->category_id))->results();
if(!count($permissions) || $permissions[0]->view != 1 || $permissions[0]->download != 1){
	// Can't view
	Redirect::to(URL::build('/resources'));
	die();
}

// Already purchased?
$already_purchased = DB::getInstance()->query('SELECT id, status FROM nl2_resources_payments WHERE resource_id = ? AND user_id = ?', array($resource->id, $user->data()->id))->results();
if(count($already_purchased)){
	$already_purchased_id = $already_purchased[0]->id;
	$already_purchased = $already_purchased[0]->status;

	if($already_purchased == 0 || $already_purchased == 1){
		// Already purchased
		Redirect::to(URL::build('/resources/resource/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))));
		die();
	}
}

if(isset($_GET['do'])){
	require_once(ROOT_PATH . '/modules/Resources/paypal.php');

	if($_GET['do'] == 'complete'){
		// Insert into database
		if(!isset($_SESSION['resource_purchasing'])){
			// Error, resource ID has been lost
			Session::flash('purchase_resource_error', $resource_language->get('resources', 'sorry_please_try_again'));
			Redirect::to(URL::build('/resources/purchase/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))));
			die();

		} else {
			$paymentId = $_GET['paymentId'];
			$payment = \PayPal\Api\Payment::get($paymentId, $apiContext);

			$execution = new \PayPal\Api\PaymentExecution();
			$execution->setPayerId($_GET['PayerID']);

			try {
				$result = $payment->execute($execution, $apiContext);

				$payment = \PayPal\Api\Payment::get($paymentId, $apiContext);

			} catch(Exception $e){
				Session::flash('purchase_resource_error', $resource_language->get('resources', 'error_while_purchasing'));
				Redirect::to(URL::build('/resources/purchase/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))));

				ErrorHandler::logCustomError($e->getMessage());
				die();
			}

			if(isset($already_purchased_id) && $already_purchased == 2){
				// Update a cancelled purchase
				$queries->update('resources_payments', $already_purchased_id, array(
					'status' => 0,
					'created' => date('U'),
					'transaction_id' => $payment->getId()
				));

			} else {
				// Create a new purchase
				$queries->create('resources_payments', array(
					'status' => 0,
					'created' => date('U'),
					'user_id' => $user->data()->id,
					'resource_id' => $resource->id,
					'transaction_id' => $payment->getId()
				));
			}

			// TODO: alerts
			//Alert::create('');
		}

	}

} else {
	if(Input::exists()){
		if(Token::check(Input::get('token'))){
			if($_POST['action'] == 'agree'){
				// Create PayPal request
				if(!file_exists(ROOT_PATH . '/modules/Resources/paypal.php')){
					$error = $resource_language->get('resources', 'paypal_not_configured');
				} else {
					$_SESSION['resource_purchasing'] = $resource->id;

					$currency = $queries->getWhere('settings', array('name', '=', 'resources_currency'));
					if(!count($currency)){
						$queries->create('settings', array(
							'name' => 'resources_currency',
							'value' => 'GBP'
						));
						$currency = 'GBP';

					} else {
						$currency = Output::getClean($currency[0]->value);
					}

					// Get author's PayPal
					$author_paypal = $queries->getWhere('resources_users_premium_details', array('user_id', '=', $resource->creator_id));
					if(!count($author_paypal) || !strlen($author_paypal[0]->paypal_email)){
						$error = $resource_language->get('resources', 'author_doesnt_have_paypal');

					} else {
						$author_paypal = Output::getClean($author_paypal[0]->paypal_email);

						require_once(ROOT_PATH . '/modules/Resources/paypal.php');

						$payer = new \PayPal\Api\Payer();
						$payer->setPaymentMethod('paypal');

						$payee = new \PayPal\Api\Payee();
						$payee->setEmail($author_paypal);

						$amount = new \PayPal\Api\Amount();
						$amount->setTotal($resource->price);
						$amount->setCurrency($currency);

						$transaction = new \PayPal\Api\Transaction();
						$transaction->setAmount($amount);
						$transaction->setPayee($payee);
						$transaction->setDescription(Output::getClean($resource->name));

						$redirectUrls = new \PayPal\Api\RedirectUrls();
						$redirectUrls->setReturnUrl(rtrim(Util::getSelfURL(), '/') . URL::build('/resources/purchase/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name)) . '/', 'do=complete'))
							->setCancelUrl(rtrim(Util::getSelfURL(), '/') . URL::build('/resources/purchase/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name)) . '/', 'do=cancel'));

						$payment = new \PayPal\Api\Payment();
						$payment->setIntent('sale')
							->setPayer($payer)
							->setTransactions(array($transaction))
							->setRedirectUrls($redirectUrls);

						try {
							$payment->create($apiContext);

							Redirect::to($payment->getApprovalLink());
							die();

						} catch (\PayPal\Exception\PayPalConnectionException $ex) {
							ErrorHandler::logCustomError($ex->getData());
							$error = $resource_language->get('resources', 'error_while_purchasing');

						}
					}
				}
			}

		} else
			$error = $language->get('general', 'invalid_token');
	}
}

$page_title = str_replace('{x}', Output::getClean($resource->name), $resource_language->get('resources', 'purchasing_resource_x'));
require_once(ROOT_PATH . '/core/templates/frontend_init.php');

if(isset($_GET['do'])){
	if($_GET['do'] == 'complete'){
		$smarty->assign(array(
			'PURCHASING_RESOURCE' => str_replace('{x}', Output::getClean($resource->name), $resource_language->get('resources', 'purchasing_resource_x')),
			'PURCHASE_COMPLETE' => $resource_language->get('resources', 'purchase_complete'),
			'BACK_LINK' => URL::build('/resources/resource/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))),
			'BACK' => $language->get('general', 'back')
		));

		$template_file = 'resources/purchase_pending.tpl';
	} else {
		$smarty->assign(array(
			'PURCHASING_RESOURCE' => str_replace('{x}', Output::getClean($resource->name), $resource_language->get('resources', 'purchasing_resource_x')),
			'PURCHASE_CANCELLED' => $resource_language->get('resources', 'purchase_cancelled'),
			'BACK_LINK' => URL::build('/resources/resource/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))),
			'BACK' => $language->get('general', 'back')
		));

		$template_file = 'resources/purchase_cancelled.tpl';
	}

} else {
	$pre_purchase_info = $queries->getWhere('privacy_terms', array('name', '=', 'resource'));
	if(!count($pre_purchase_info)){
		$pre_purchase_info = '<p>You will be redirected to PayPal to complete your purchase.</p><p>Access to the download will only be granted once the payment has been completed, this may take a while.</p><p>Please note, ' . SITE_NAME . ' can\'t take any responsibility for purchases that occur through our resources section. If you experience any issues with the resource, please contact the resource author directly.</p><p>If your access to ' . SITE_NAME . ' is revoked (for example, your account is banned), you will lose access to any purchased resources.</p>';

		$queries->create('privacy_terms', array(
			'name' => 'resource',
			'value' => $pre_purchase_info
		));
	} else
		$pre_purchase_info = Output::getPurified($pre_purchase_info[0]->value);

	// Assign Smarty variables
	$smarty->assign(array(
		'PURCHASING_RESOURCE' => str_replace('{x}', Output::getClean($resource->name), $resource_language->get('resources', 'purchasing_resource_x')),
		'CANCEL' => $language->get('general', 'cancel'),
		'CONFIRM_CANCEL' => $language->get('general', 'confirm_cancel'),
		'CANCEL_LINK' => URL::build('/resources/resource/' . Output::getClean($resource->id . '-' . Util::stringToURL($resource->name))),
		'PRE_PURCHASE_INFO' => $pre_purchase_info,
		'PURCHASE' => $resource_language->get('resources', 'purchase'),
		'TOKEN' => Token::get()
	));

	$template_file = 'resources/purchase.tpl';
}

if(Session::exists('purchase_resource_error'))
	$error = Session::flash('purchase_resource_error');

if(isset($error))
	$smarty->assign('ERROR', $error);

// Load modules + template
Module::loadPage($user, $pages, $cache, $smarty, array($navigation, $cc_nav, $mod_nav), $widgets);

$page_load = microtime(true) - $start;
define('PAGE_LOAD_TIME', str_replace('{x}', round($page_load, 3), $language->get('general', 'page_loaded_in')));

$template->onPageLoad();

require(ROOT_PATH . '/core/templates/navbar.php');
require(ROOT_PATH . '/core/templates/footer.php');

$template->displayTemplate($template_file, $smarty);