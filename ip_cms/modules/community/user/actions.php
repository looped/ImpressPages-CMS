<?php
/**
 * @package		ImpressPages
 * @copyright	Copyright (C) 2009 JSC Apro media.
 * @license		GNU/GPL, see licence.html
 */

namespace Modules\community\user;
 
if (!defined('CMS')) exit;

require_once __DIR__.'/db.php';

require_once(LIBRARY_DIR."/php/form/standard.php");
require_once(MODULE_DIR."/administrator/email_queue/module.php");

	class Actions{
		
		function makeActions(){
			global $site;
			global $parametersMod;
			global $session;
			global $log;


			$userZone = $site->getZoneByModule('community', 'user');
			if(!$userZone)
				return;


    if(isset($_REQUEST['action'])){
      switch($_REQUEST['action']){
        case 'password_reset':
          $standardForm = new \Library\Php\Form\Standard(\Modules\community\user\Config::getPasswordResetFields());
          $errors = $standardForm->getErrors();
          
          $tmpUser = Db::userByEmail($_POST['email']);
          if(!$tmpUser)
            $errors['email'] = $parametersMod->getValue('community', 'user', 'errors', 'email_dont_exist');
          
          if(!isset($_POST['password']) || $_POST['password'] == '' || $_POST['password'] != $_POST['confirm_password']){
            $errors['password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
            $errors['confirm_password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
          }
                      
            
          if (sizeof($errors) > 0) {  
            $html = $standardForm->generateErrorAnswer($errors);
          }else {
            $tmp_code = md5(uniqid(rand(), true));
            $additionalFields['new_password'] = md5($_POST['password']);
            $additionalFields['verification_code'] = $tmp_code;                  
              
            $standardForm->updateDatabase(DB_PREF.'m_community_user', 'id', $tmpUser['id'], $additionalFields);

            $this->sendPasswordResetLink($_POST['email'], $tmp_code, $tmpUser['id']);
            
            $html = "
                <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                <script type=\"text/javascript\">
                  parent.window.location = '".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetSentText))."';
                </script>
                </body></html>            
            ";
              

          }
          echo $html;
          \Db::disconnect();
  			  exit;
          
        break;
        case 'password_reset_verification':
  			  $current = Db::userById($_REQUEST['id']);
  			  if($current && $current['verified']){    			  
  			   if($current['verification_code'] == $_REQUEST['code']){
  			     if($current['new_password'] != ''){
			         if(Db::verifyNewPassword($current['id'])){
    			       header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetVerified)));
			         } else {
      			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetVerificationError)));
			         }
  			     } else {			         
  			       header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetVerified)));
  			     }
  			   } else {
  			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetVerificationError)));
  			   }			  
  			  } else {
  			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlPasswordResetVerificationError)));
  			  }
          \Db::disconnect();
  			  exit;
        break;
        case 'register':
          if($parametersMod->getValue('community','user','options','registration_disabled')){
            \Db::disconnect();
            exit;        
          }
          $standardForm = new \Library\Php\Form\Standard(\Modules\community\user\Config::getRegistrationFields());
          $errors = $standardForm->getErrors();
          
          $sameEmailUser = Db::userByEmail($_POST['email']);
          
          if($_POST['email'] && $sameEmailUser)
            $errors['email'] = $parametersMod->getValue('community', 'user', 'errors', 'already_registered');
           
            
          if($parametersMod->getValue('community','user','options','login_type') == 'login'){
            $sameLoginUser = Db::userByLogin($_POST['login']);
            if($sameLoginUser)
              $errors['login'] = $parametersMod->getValue('community', 'user', 'errors', 'already_registered');
          }
            
          if($parametersMod->getValue('community','user','options','type_password_twice') && $_POST['password'] != $_POST['confirm_password']){
            $errors['password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
            $errors['confirm_password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
          }
            
          
          
          if(sizeof($errors) > 0)  
            $html = $standardForm->generateErrorAnswer($errors);
          else{          
              $tmp_code = md5(uniqid(rand(), true));
              if($parametersMod->getValue('community', 'user', 'options', 'use_password_hash')){
                $password = md5($_POST['password'].\Modules\community\user\Config::$hashSalt);
              } else {
                $password = $_POST['password'];
              }
              $insert_id = $standardForm->writeToDatabase(DB_PREF.'m_community_user', array('verification_code' => $tmp_code, 'password' => $password, 'last_login'=>date("Y-m-d"), 'language_id'=>$site->currentLanguage['id']));
              if($insert_id !== false){
                $this->sendVerificationLink($_POST['email'], $tmp_code, mysql_insert_id());
                $html = "
                  <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                  <script type=\"text/javascript\">
                    parent.window.location = '".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerificationRequired))."';
                  </script>
                  </body></html>
                ";  
              } else {
                trigger_error("Cannot register new user");              
              }
          }
          echo $html;
          \Db::disconnect();
          exit;
        break;
        
        case 'update_profile':
          if($session->loggedIn()){
            $standardForm = new \Library\Php\Form\Standard(\Modules\community\user\Config::getProfileFields());
            $errors = $standardForm->getErrors();
            
            $tmpUser = Db::userById($session->userId());
            
            if(isset($_POST['email']) && $_POST['email'] != $tmpUser['email']){
              $user_by_new_email = Db::userByEmail($_POST['email']);
              if($user_by_new_email && $user_by_new_email['verified'])
                $errors['email'] = $parametersMod->getValue('community', 'user', 'errors', 'already_registered');
              
            }
            
            
            if($parametersMod->getValue('community','user','options','type_password_twice') && $_POST['password'] != $_POST['confirm_password']){
              $errors['password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
              $errors['confirm_password'] = $parametersMod->getValue('community', 'user', 'errors', 'passwords_dont_match');
            }
            
            
                        
            if(sizeof($errors) > 0)  
              $html = $standardForm->generateErrorAnswer($errors);
            else{          
              if($tmpUser){
                $additionalFields = array();
                
                if(isset($_POST['email']) && $_POST['email'] != $tmpUser['email']){
                  $tmp_code = md5(uniqid(rand(), true));
                  $additionalFields['new_email'] = $_POST['email'];
                  $additionalFields['verification_code'] = $tmp_code;                  
                }
                
                if(isset($_POST['password']) && $_POST['password'] != ''){
                  if($parametersMod->getValue('community', 'user', 'options', 'use_password_hash')){
                    $additionalFields['password'] =  md5($_POST['password'].\Modules\community\user\Config::$hashSalt);
                  }else $additionalFields['password'] =  md5($_POST['password']);
                }
                
                
                
                $standardForm->updateDatabase(DB_PREF.'m_community_user', 'id', $tmpUser['id'], $additionalFields);
                                
                                
                if(isset($_POST['email']) && $_POST['email'] != $tmpUser['email']){
                  $this->sendUpdateVerificationLink($_POST['email'], $tmp_code, $tmpUser['id']);
                  $html = "
                    <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                    <script type=\"text/javascript\">
                      parent.window.location = '".$site->generateUrl(null, $userZone->getName(), array(Config::$urlEmailVerificationRequired))."';
                    </script>
                    </body></html>
                  ";  
                  
                }else{
                  $html = "
                    <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                    <script type=\"text/javascript\">
                      parent.window.location = '".$site->generateUrl(null, $userZone->getName(), array(Config::$urlProfile), array("message"=>"updated"))."';
                    </script>
                    </body></html>
                  ";  
                }
                
              }else{
                trigger_error("Something goes wrong. ".$session->userId()." ".$_POST['email']);
              }
            }  
          echo $html;
          \Db::disconnect();
          exit;
            
                
          }
          
        break;
        
        case 'login':
          if($parametersMod->getValue('community','user','options','login_type') == 'login')
            $tmpUser = Db::userByLogin($_POST['login']);
          else
            $tmpUser = Db::userByEmail($_POST['email']);
            
          if($parametersMod->getValue('community', 'user', 'options', 'use_password_hash')){
            $tmp_password = md5($_POST['password'].\Modules\community\user\Config::$hashSalt);
          }else $tmp_password = $_POST['password'];
                      
          if($tmpUser && isset($_POST['password']) && $tmp_password == $tmpUser['password']){
            $session->login($tmpUser['id']);
            Db::loginTimestamp($tmpUser['id']);
            $log->log('community/user', 'frontend login', $tmpUser['login']." ".$tmpUser['email']." ".$_SERVER['REMOTE_ADDR']);
            
            if(isset($_SESSION['modules']['community']['user']['page_after_login'])){
              $html = "
                  <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                  <script type=\"text/javascript\">
                    parent.window.location = '".$_SESSION['modules']['community']['user']['page_after_login']."';
                  </script>
                  </body></html>
              "; 
              
              unset($_SESSION['modules']['community']['user']['page_after_login']);             
            }else{
              if($parametersMod->getValue('community', 'user', 'options', 'zone_after_login')){
                $html = "
                    <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                    <script type=\"text/javascript\">
                      parent.window.location = '".$site->generateUrl(null, $parametersMod->getValue('community', 'user', 'options', 'zone_after_login'))."';
                    </script>
                    </body></html>
                ";
              }else{
                $html = "
                    <html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=".CHARSET."\" /></head><body>
                    <script type=\"text/javascript\">
                      parent.window.location = parent.window.location;
                    </script>
                    </body></html>
                ";
              }
            }
            
          }else{
            $standardForm = new \Library\Php\Form\Standard(\Modules\community\user\Config::getRegistrationFields());
            $errors = array();
            $globalError = null;
            if($parametersMod->getValue('community','user','options','login_type') == 'login'){
              $globalError = $parametersMod->getValue('community', 'user', 'errors', 'incorrect_login_data');
              $errors['login'] = '';
            }else{
              $globalError = $parametersMod->getValue('community', 'user', 'errors', 'incorrect_email_data');
              $errors['email'] = '';              
            }
            $errors['password'] = '';
            $log->log('community/user', 'incorrect frontend login', $_SERVER['REMOTE_ADDR']);            
            $html = $standardForm->generateErrorAnswer($errors, $globalError);
          }
            
          echo $html;
          \Db::disconnect();
          exit;
        break;
        
			case 'registration_verification':
			  $current = Db::userById($_REQUEST['id']);
			  if($current){
  			  $sameEmailUser = Db::userByEmail($current['email']);
	   		  $sameLoginUser = Db::userByLogin($current['login']);

			   if($current['verification_code'] == $_REQUEST['code']){
			     if($sameEmailUser && $sameEmailUser['id'] != $current['id']){
  			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlVerificationErrorEmailExist)));
			     }elseif($parametersMod->getValue('community','user','options','login_type') == 'login' && $sameLoginUser && $sameLoginUser != $current['id']){
  			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlVerificationErrorUserExist)));
			     }else{
  			     Db::verify($current['id']);
  			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerified)));
			     }
			   }else{
			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerificationError)));
			   }			  
			  }else
			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerificationError)));
        \Db::disconnect();
			  exit;
			break;
			
			
			case 'new_email_verification':
			  $sameEmailUser = Db::userById($_REQUEST['id']);
			  if($sameEmailUser){
			   if($sameEmailUser['verification_code'] == $_REQUEST['code']){
			     $user_with_new_email = Db::userByEmail($sameEmailUser['new_email']);
			     if($user_with_new_email){
			       if($user_with_new_email['id'] == $sameEmailUser['id']){
    			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerified)));
			       }else{
    			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlNewEmailVerificationError)));
			       }
			     
			     }else{
			       if($sameEmailUser['new_email'] == ''){
    			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlRegistrationVerified)));
			       }else{
  			       Db::verifyNewEmail($sameEmailUser['id']);
  			       header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlNewEmailVerified)));
  			    }
			     }
			   }else{
			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlNewEmailVerificationError)));
			   }			  
			  }else
			     header("location: ".$site->generateUrl(null, $userZone->getName(), array(Config::$urlNewEmailVerificationError)));
           
        \Db::disconnect();
        exit;			  
			break;
			
			case 'logout':
        $session->logout();
        header('location: '.BASE_URL);
        \Db::disconnect();
        exit;
      break;
      
			case 'renew_registration':
			  if(isset($_GET['id'])){
			    if(Db::renewRegistration($_GET['id']) == 1)
            header('location: '.$site->generateUrl(null, $userZone->getName(), array(Config::$urlRenewedRegistration)));        
          else
            header('location: '.$site->generateUrl(null, $userZone->getName(), array(Config::$urlRenewRegistrationError)));        
        }else
          header('location: '.$site->generateUrl(null, $userZone->getName(), array(Config::$urlRenewRegistrationError)));
        \Db::disconnect();
        exit;
      break;
      }
    
    }

  }
		
		
  function sendPasswordResetLink($email, $code, $userId){
    require_once(BASE_DIR.LIBRARY_DIR.'php/text/system_variables.php');
    global $parametersMod;
    global $site;
    
		$emailQueue = new \Modules\administrator\email_queue\Module();
		$emailHtml = str_replace('[[content]]', $parametersMod->getValue('community', 'user', 'email_messages', 'password_reset_text'), $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email_template'));
		$link = $site->generateUrl(null, null, array(), array("module_group" => "community", "module_name" => "user", "action" => "password_reset_verification", "id" => $userId, "code" => $code)); 
		$emailHtml = str_replace('[[link]]', '<a href="'.$link.'">'.$link.'</a>', $emailHtml);

    $emailHtml = \Library\Php\Text\SystemVariables::insert($emailHtml);
    $emailHtml = \Library\Php\Text\SystemVariables::clear($emailHtml);
		
		
		$emailQueue->addEmail(
			$parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email'), 
      $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'name'),
			$email,
      '',
			$parametersMod->getValue('community', 'user', 'email_messages', 'password_reset_subject'),
			$emailHtml,
			true, true, null);
		$emailQueue->send();
  }		
		
		
  function sendVerificationLink($email, $code, $userId){
    require_once(BASE_DIR.LIBRARY_DIR.'php/text/system_variables.php');
    global $parametersMod;
    global $site;
    
		$emailQueue = new \Modules\administrator\email_queue\Module();
		$emailHtml = str_replace('[[content]]', $parametersMod->getValue('community', 'user', 'email_messages', 'verify_registration_text'), $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email_template'));
		$link = $site->generateUrl(null, null, array(), array("module_group" => "community", "module_name" => "user", "action" => "registration_verification", "id" => $userId, "code" => $code)); 
		$emailHtml = str_replace('[[link]]', '<a href="'.$link.'">'.$link.'</a>', $emailHtml);

		$emailHtml = \Library\Php\Text\SystemVariables::insert($emailHtml);
    $emailHtml = \Library\Php\Text\SystemVariables::clear($emailHtml);
    
		$emailQueue->addEmail(
			$parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email'), 
      $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'name'),
			$email,
      '',
			$parametersMod->getValue('community', 'user', 'email_messages', 'verify_registration_subject'),
			$emailHtml,
			true, true, null);
		$emailQueue->send();
  }

  function sendUpdateVerificationLink($email, $code, $userId){
    require_once(BASE_DIR.LIBRARY_DIR.'php/text/system_variables.php');
    
    global $parametersMod;
    global $site;
    
		$emailQueue = new \Modules\administrator\email_queue\Module();
		$emailHtml = str_replace('[[content]]', $parametersMod->getValue('community', 'user', 'email_messages', 'verify_new_email_text'), $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email_template'));
		$link = $site->generateUrl(null, null, array(), array("module_group" => "community", "module_name" => "user", "action" => "new_email_verification", "id" => $userId, "code" => $code)); 
		$emailHtml = str_replace('[[link]]', '<a href="'.$link.'">'.$link.'</a>', $emailHtml);

    $emailHtml = \Library\Php\Text\SystemVariables::insert($emailHtml);
    $emailHtml = \Library\Php\Text\SystemVariables::clear($emailHtml);
		
		$emailQueue->addEmail(
			$parametersMod->getValue('standard', 'configuration', 'main_parameters', 'email'), 
      $parametersMod->getValue('standard', 'configuration', 'main_parameters', 'name'),
			$email,
      '',
			$parametersMod->getValue('community', 'user', 'email_messages', 'verify_new_email_subject'),
			$emailHtml,
			true, true, null);
		$emailQueue->send();
  }
		
		
		
	}

		
   