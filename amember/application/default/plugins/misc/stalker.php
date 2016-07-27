<?php
class Am_Plugin_Stalker extends Am_Plugin
{
    function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('stalker_id', "Stalker External product Id"));

    }
    
    protected $_configPrefix = 'misc.';
    function _initSetupForm(Am_Form_Setup $form)
    {
     $form->setTitle('StalkerConfig');
     $form->addText('stalker_api', array('size' => 70))
         ->setLabel(array("Stalker API URL","Enter right Stalker API URL, like: 
         http://apilogin:apipass@example.com/stalker_portal/api/"))->addRule('required');
     $form->addInteger('stb_category')->setLabel("STB category ID")->addRule('required');
     $form->addText('stalker_api_admin', array('size' => 40))->setLabel(array("Admin e-mail","E-mail for error reporting"))->addRule('required');
     $form->addText("mySITENAME")->setLabel("Site name");
     $form->addSelect('stalker_type', array(),array('options' =>
         array(
         'users'=>'users',
         'accounts'=>'accounts')))->setLabel('API type');
     $form->addAdvCheckbox('user_stb_disable')->setLabel(array('User can disable STB'));
     }
function login2mac ($login) {
    $mac_test = preg_match('/^[a-fA-F0-9]{12}$/', $login);
    if (strlen($login) == 12 && $mac_test == 1) {
    $mac = preg_replace('/(..)(..)(..)(..)(..)(..)/', '$1:$2:$3:$4:$5:$6', $login);
    } else {$mac = '0';}
    return $mac;
}
function getSTBAPIParams (User $user) {
    if ($this->getConfig('stalker_type') == 'users') {
    $urireq = $this->getConfig('stalker_api').'users';
    $userpar = $user->login; 
    } else {
    $urireq = $this->getConfig('stalker_api').'accounts';
    $user->updateQuick('mac_address', $this->login2mac($user->login));
    $userpar = $user->mac_address;            
    }
    return array('urireq' => $urireq, 'user' => $userpar);
}    
function mySTBCheckRequest(Am_Event $event) {
    $user = $event->getUser(); 
    $uriparam = $this->getSTBAPIParams($user);
    $request = new Am_HttpRequest($uriparam['urireq']."/".$uriparam['user'], Am_HttpRequest::METHOD_GET);
    $jsonreq = $request->send()->getBody();
    return json_decode($jsonreq, TRUE);
    }
    function chckResult ($resultarray) {
    $result = FALSE;
    if ($resultarray['status'] == 'OK' && $resultarray['results'] == 'true') {
            $result = TRUE;
    }
    return $result;
}
function chckStatus ($resultarray) {
    $result = FALSE;
    if (!is_null($resultarray['status']) && $resultarray['status'] == 'OK') {
            $result = TRUE;
    }
    return $result;
}
function SendToAdmin ($event_id, $login, $msg) {
$mail = Am_Di::getInstance()->mail;
$mail->addTo($this->getConfig('stalker_api_admin'), $this->getConfig('mySITENAME'). "Admin");
$mail->setSubject("WARNING from: " . $this->getConfig('mySITENAME'));
$msgbody = "Action ".$event_id . "  Failed!\n";
$msgbody .= "login: ".$login ."\n";
if (!is_null($msg)) {$msgbody .= $msg . "\n";}
$mail->setBodyText($msgbody);
try {
   $mail->send();
    } catch (Exception $e) {
   echo "Error sending e-mail: " . $e->getMessage() . "\n";
        }
    }
function checkUserCateroryIsSTB (User $user) {
    $product_ids = $user->getActiveProductIds();
    $stb_tariffs = Am_Di::getInstance()->productCategoryTable->getCategoryProducts();
    $userSTBCategory = array_intersect($product_ids, $stb_tariffs[$this->getConfig('stb_category')]);
    if (count($userSTBCategory) > 0) {return TRUE;}
    else  {return FALSE;}
    }

function onSubscriptionAdded (Am_Event $event)
{
    $access = $event->getProduct();
    $user = $event->getUser(); 
    $uriparam = $this->getSTBAPIParams($user);
    $product_id = $access->product_id;
    $stb_tariffs = Am_Di::getInstance()->productCategoryTable->getCategoryProducts();
    if (in_array($product_id, $stb_tariffs[$this->getConfig('stb_category')])) {
    $tariff_plan_data = $access->getBillingPlanData();
    $tariff_plan = $tariff_plan_data['stalker_id'];
    $params = array(
    'login' => $user->login,
    'tariff_plan' => $tariff_plan,
    'password' => $user->stb_pass,
    'status' => "1",
    );
    if ($this->getConfig('stalker_type') == 'accounts' && $uriparam['user'] != '0') {
     $params['stb_mac'] = $user->mac_address;
        } 
    $actionresult = FALSE;
    $stbcheck = $this->mySTBCheckRequest($event);
    if ($this->chckStatus($stbcheck)) {
    $request = new Am_HttpRequest( $uriparam['urireq']."/". $uriparam['user'], Am_HttpRequest::METHOD_PUT);
    $request->setBody(http_build_query($params));
    $apianswerjs = $request->send()->getBody();
    $apianswer = json_decode($apianswerjs,TRUE);
    $actionresult = $this->chckResult($apianswer);
        } else {
    $request = new Am_HttpRequest($uriparam['urireq']."/".$uriparam['user'], Am_HttpRequest::METHOD_POST);
    $request->addPostParameter($params);
    $apianswerjs = $request->send()->getBody();
    $apianswer = json_decode($apianswerjs,TRUE);
    $actionresult = $this->chckResult($apianswer);
            }
    if (!$actionresult) {$this->SendToAdmin($event->getId(), $user->login, $apianswerjs); } 
    }
}
  
function onSubscriptionDeleted(Am_Event $event) {
    $user = $event->getUser();
    $access = $event->getProduct();
    $product_id = $access->product_id;
    $stb_tariffs = Am_Di::getInstance()->productCategoryTable->getCategoryProducts();
    if (in_array($product_id, $stb_tariffs[$this->getConfig('stb_category')])) {
    $uriparam = $this->getSTBAPIParams($user);
    $params = '&status=' . "0";
    $request = new Am_HttpRequest($uriparam['urireq']."/". $uriparam['user'], Am_HttpRequest::METHOD_PUT);
    $request->setBody($params);
    $apianswerjs = $request->send()->getBody();
    $apianswer = json_decode($apianswerjs, TRUE);
    if (!$this->chckResult($apianswer)) {
    $this->SendToAdmin($event->getId(), $user->login, $apianswerjs);
        }
    }
}

function onUserAfterDelete(Am_Event $event) {
    $user = $event->getUser();
    $uriparam = $this->getSTBAPIParams($user);
    $request = new Am_HttpRequest($uriparam['urireq']."/". $uriparam['user'], Am_HttpRequest::METHOD_DELETE);
    $apianswerjs = $request->send()->getBody();
    $apianswer = json_decode($apianswerjs, TRUE);
    if (!$this->chckResult($apianswer)) {
    $this->SendToAdmin($event->getId(), $user->login, $apianswerjs);
    }
}
function onUserAfterUpdate(Am_Event $event) {
    $user = $event->getUser();
    if ($this->checkUserCateroryIsSTB($user)) {
    $uriparam = $this->getSTBAPIParams($user);
    if ($this->getConfig('user_stb_disable')) {$stb_enable = $user->stb_enable;}
    else {$stb_enable = '1';}
        if (!$user->is_locked && $stb_enable == '1') {
        $status = '1';
    } else {$status = '0';}
    $params .= '&password='. $user->stb_pass;
    $params .= '&status=' . $status;
    $request = new Am_HttpRequest($uriparam['urireq']."/". $uriparam['user'], Am_HttpRequest::METHOD_PUT);
    $request->setBody($params);
    $apianswerjs = $request->send()->getBody();
    $apianswer = json_decode($apianswerjs, TRUE);
    if (!$this->chckResult($apianswer)) {
    $this->SendToAdmin($event->getId(), $user->login, $apianswerjs);
        }
    }
    }

}