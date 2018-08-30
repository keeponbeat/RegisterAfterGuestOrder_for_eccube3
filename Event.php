<?php

/*
  * This file is part of the RegisterAfterGuestOrder plugin
  *
  * Copyright (C) 2017 MURAKAMI INTERNATIONAL LLC. All Rights Reserved.
  *
  * Website: http://murakami-international.co.jp
  * E-Mail: info@murakami-international.co.jp
  * 
  * For the full copyright and license information, please view the LICENSE
  * file that was distributed with this source code.
  */

namespace Plugin\RegisterAfterGuestOrder;

use Eccube\Application;
use Eccube\Common\Constant;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\CssSelector\CssSelector;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception as HttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Validator\Constraints;

class Event
{
    /** @var \Eccube\Application $app */
    private $app;

    /**
     * @var string 非会員用セッションキー
     */
    private $sessionKey = 'eccube.front.shopping.nonmember';

    /**
     * @var string 非会員用セッションキー
     */
    private $sessionCustomerAddressKey = 'eccube.front.shopping.nonmember.customeraddress';

    /**
     * @var string 複数配送警告メッセージ
     */
    private $sessionMultipleKey = 'eccube.front.shopping.multiple';

    /**
     * @var string 受注IDキー
     */
    private $sessionOrderKey = 'eccube.front.shopping.order.id';


    public function __construct($app)
    {
        $this->app = $app;
    }

	public Function onFrontShoppingCompleteInit(EventArgs $event)
	{
		$app = $this->app;
		$request = $event->getRequest();
		$orderId = $event->getArgument('orderId');
		$app['session']->set('plg_register_after_guest_order.orderid',$orderId);

		$builder = $app['form.factory']->createBuilder();
		$builder->add('plg_register_after_guest_order_password','repeated_password',array(
					'label' => 'パスワード',
				))
				->add('plg_register_after_guest_order_orderId','hidden',array(
					
					'data' => $orderId,
				))
				->add('plg_register_after_guest_order_send', 'submit', array('label' => '会員登録する'));
		$form = $builder->getForm();

		$form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $password = $form->get('plg_register_after_guest_order_password')->getData();
            $orderId = $form->get('plg_register_after_guest_order_orderId')->getData();
            $Order = $app['eccube.repository.order']->find($orderId);
            $email = $Order->getEmail();
            $Customer = $app['eccube.repository.customer']->findOneBy(array('email' => $email, 'del_flg' => 0,));

            //セッションのorderIdとhiddenフォームのorderIdが違う場合
            if($orderId != $app['session']->get('plg_register_after_guest_order.orderid')){
				$event->setResponse(
					$app->render('error.twig', array(
                		'error_title' => '認証エラー',
                		'error_message' => 'アプリケーション認証エラーです。',
            		))
				);
				return;
            }
    
            if($Customer){
				$event->setResponse(
					$app->render('error.twig', array(
                		'error_title' => 'データベースエラー',
                		'error_message' => 'ご注文情報のメールアドレスは、既に仮登録メールをお送りしているか、既に会員登録されています。',
            		))
				);
				return;
            }else{
            	$Customer = $app['eccube.repository.customer']->newCustomer();
            }

            //会員登録処理
            if(!empty($password)){
            	log_info('ゲスト購入後会員登録処理開始', array($orderId));

            	$Customer->setPassword($password);
            	$Customer->setSalt($app['eccube.repository.customer']->createSalt(5));
            	$Customer->setPassword($app['eccube.repository.customer']->encryptPassword($app, $Customer));
				$Customer->setSecretKey($app['orm.em']->getRepository('Eccube\Entity\Customer')->getUniqueSecretKey($app));

				$Customer->setName01($Order->getName01())
            	->setName02($Order->getName02())
            	->setKana01($Order->getKana01())
            	->setKana02($Order->getKana02())
            	->setCompanyName($Order->getCompanyName())
            	->setZip01($Order->getZip01())
            	->setZip02($Order->getZip02())
            	->setZipcode($Order->getZip01() . $Order->getZip02())
            	->setPref($app['eccube.repository.master.pref']->find($Order['Pref']->getId()))
            	->setAddr01($Order->getAddr01())
            	->setAddr02($Order->getAddr02())
            	->setEmail($Order->getEmail())
            	->setTel01($Order->getTel01())
            	->setTel02($Order->getTel02())
            	->setTel03($Order->getTel03())
            	->setFax01($Order->getFax01())
            	->setFax02($Order->getFax02())
            	->setFax03($Order->getFax03());

	            $CustomerAddress = new \Eccube\Entity\CustomerAddress();
    	        $CustomerAddress->setName01($Customer->getName01())
        	    ->setName02($Customer->getName02())
            	->setKana01($Customer->getKana01())
            	->setKana02($Customer->getKana02())
            	->setCompanyName($Customer->getCompanyName())
            	->setZip01($Customer->getZip01())
            	->setZip02($Customer->getZip02())
            	->setZipcode($Customer->getZip01() . $Customer->getZip02())
            	->setPref($Customer->getPref())
            	->setAddr01($Customer->getAddr01())
            	->setAddr02($Customer->getAddr02())
            	->setTel01($Customer->getTel01())
            	->setTel02($Customer->getTel02())
            	->setTel03($Customer->getTel03())
            	->setFax01($Customer->getFax01())
            	->setFax02($Customer->getFax02())
            	->setFax03($Customer->getFax03())
            	->setDelFlg(Constant::DISABLED)
            	->setCustomer($Customer);

            	// トランザクション制御
            	$em = $app['orm.em'];
            	$em->getConnection()->beginTransaction();
            
            	try {
                	$app['orm.em']->persist($Customer);
                	$app['orm.em']->persist($CustomerAddress);

                	$Order->setCustomer($Customer);
                	$activateUrl = $app->url('entry_activate', array('secret_key' => $Customer->getSecretKey()));

                	$BaseInfo = $app['eccube.repository.base_info']->get();
                	$activateFlg = $BaseInfo->getOptionCustomerActivate();

                	// 仮会員設定が有効な場合は、確認メールを送信.
                	if ($activateFlg) {
                    	// メール送信
                    	$app['eccube.service.mail']->sendCustomerConfirmMail($Customer, $activateUrl);

                    	$em->getConnection()->commit();
                    	$em->flush();
                    	$em->close();

                    	$event->setResponse($app->redirect($app->url('entry_complete')));
                    	return;

	                // 仮会員設定が無効な場合
    	            } else {
        	            // 本会員登録してログイン状態にする
            	        $Status = $app['orm.em']
                	        ->getRepository('Eccube\Entity\Master\CustomerStatus')
                    	    ->find(2);
                    	$Customer->setStatus($Status);
                    	$app['orm.em']->persist($Customer);

                    	// メール送信
                    	$app['eccube.service.mail']->sendCustomerCompleteMail($Customer);

	                    // ログイン状態にする
    	                $token = new UsernamePasswordToken($Customer, null, 'customer', array('ROLE_USER'));
        	            $app['security.token_storage']->setToken($token);

            	        if ($app->isGranted('ROLE_USER')) {
                	        // 会員の場合、購入金額を更新
                    	    $app['eccube.service.order']->setCustomerUpdate($em, $Order, $app->user());
                    	}

                    	$em->getConnection()->commit();
                    	$em->flush();
                    	$em->close();

                    	// 受注に関連するセッションを削除
			        	$app['session']->remove($this->sessionOrderKey);
        				$app['session']->remove($this->sessionMultipleKey);
        				// 非会員用セッション情報を空の配列で上書きする(プラグイン互換性保持のために削除はしない)
        				$app['session']->set($this->sessionKey, array());
        				$app['session']->set($this->sessionCustomerAddressKey, array());

	        			$app['session']->remove('plg_register_after_guest_order.orderid');
				        log_info('ゲスト購入後会員登録処理完了', array($orderId));

	                    $event->setResponse($app->redirect($app->url('mypage')));
	                    return;
	                }

            	} catch (\Exception $e) {
                	$em->getConnection()->rollback();
                	$em->close();

             		// 受注に関連するセッションを削除
			        $app['session']->remove($this->sessionOrderKey);
        			$app['session']->remove($this->sessionMultipleKey);
        			// 非会員用セッション情報を空の配列で上書きする(プラグイン互換性保持のために削除はしない)
        			$app['session']->set($this->sessionKey, array());
        			$app['session']->set($this->sessionCustomerAddressKey, array());

        			$app['session']->remove('plg_register_after_guest_order.orderid');
			        log_info('ゲスト購入後会員登録処理失敗', array($orderId));

                	$event->setResponse(
						$app->render('error.twig', array(
                			'error_title' => 'エラー：'.$e->getCode(),
                			'error_message' => '会員登録中にエラーが発生しました。 message: '.$e->getmessage(),
            				)
						)
                	);
					return;
            	}
            }
        }

        // 受注に関連するセッションを削除
        $app['session']->remove($this->sessionMultipleKey);
        // 非会員用セッション情報を空の配列で上書きする(プラグイン互換性保持のために削除はしない)
        $app['session']->set($this->sessionKey, array());
        $app['session']->set($this->sessionCustomerAddressKey, array());

        log_info('購入処理完了', array($orderId));

        $event->setResponse(
        	$app->render('Shopping/complete.twig', array(
        		'form' => $form->createView(),
        		'orderId' => $orderId,
        	))
        );
	}


    public function onShoppingCompleteRender(TemplateEvent $event)
    {
    	$app = $this->app;
    	$params = $event->getParameters();
    	$orderId = $params['orderId'];
    	$email = $app['eccube.repository.order']->find($orderId)->getEmail();
    	$Customer = $app['eccube.repository.customer']->findOneBy(array('email' => $email, 'del_flg' => 0,));

    	if ($app->isGranted('ROLE_USER') || $Customer) {
        	return;
    	}

        $source = $event->getSource();

        $target = '<div id="deliveradd_input_box__top_button" class="row no-padding">';
        $parts = $app['twig']->getLoader()->getSource('RegisterAfterGuestOrder/Resource/template/parts.twig');

        $style = <<< EOT
<style>
#plg_register_after_guest_order_message h2{
	text-align:center;
	padding-top:50px;
}
#plg_register_after_guest_order_message p{
	display:block;
	box-sizing:border-box;
	font-size:16px;
	text-align:center;
	margin:15px 0 0 0;
	padding: 20px 0 0 5px;
	border-top: 1px dotted gray;
}
div.form-group{
	display:block;
	width:100%;
}
.dl_table dl{
	clear:both;
	box-sizing:border-box;
	padding:0;
	margin:0;
	width:100%;
}
.dl_table dt{
	display:block !important;
	width:25%;
	float:left;
}
.dl_table dt label{
	line-height:96px;
}
.dl_table dd{
	display:block !important;
	width:75%;
	float:left;
}
#plg_register_after_guest_order_send_wrapper{
	display:block !important;
	width:100%;
	text-align:center;
	margin:20px 0 60px 0;
}
#plg_register_after_guest_order_message button{
    margin:0 auto;
    text-align:center;
    border:0;
    width:230px;
    height:45px;
    background: #89c997;
    color:white;
    font-size:17px;
    font-weight:bold;
}
#plg_register_after_guest_order_message button:hover{
	background: #80c088;
}
#plg_register_after_guest_order_message button:active{
	background: #89c997;
}
</style>
EOT;
        $replace = $parts.$style.$target;
        $source = str_replace($target, $replace, $source);
        $event->setSource($source);
    }

}