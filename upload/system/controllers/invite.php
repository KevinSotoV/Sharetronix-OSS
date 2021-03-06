<?php
	
	if( !$this->network->id ) {
		$this->redirect('home');
	}
	if( !$this->user->is_logged ) {
		$this->redirect('signin');
	}
	
	$this->load_langfile('inside/global.php');
	$this->load_langfile('inside/invite.php');
	
	$formdata	= array();
	
	$submit	= FALSE;
	if( isset($_POST['name'], $_POST['email']) && is_array($_POST['name']) && is_array($_POST['email']) ) {
		global $plugins_manager;
		$plugins_manager->onPageSubmit();
		if( !$plugins_manager->isValidEventCall() ){
			$error = TRUE;
			$errmsg = $plugins_manager->getEventCallErrorMessage();
		}
		
		$submit	= TRUE;
		foreach($_POST['name'] as $i=>$v) {
			$tmp	= (object) array (
				'name'	=> trim($v),
				'email'	=> isset($_POST['email'][$i]) ? trim($_POST['email'][$i]) : '',
			);
			if( empty($tmp->name) && empty($tmp->email) ) {
				continue;
			}
			$dupl	= FALSE;
			foreach($formdata as $sdf) {
				if($sdf->email==$tmp->email ) {
					$dupl	= TRUE;
					break;
				}
			}
			if($dupl) {
				continue;
			}
			$formdata[]	= $tmp;
		}
	}
	if( 0 == count($formdata) ) {
		for($i=0; $i<5; $i++) {
			$formdata[]	= (object) array (
				'name'	=> '',
				'email'	=> '',
			);
		}
	}
	
	$error = FALSE;
	$errmsg = '';
	$okmsg = '';
	if( $submit ) {
		foreach($formdata as $tmp) {
			if( empty($tmp->name) || empty($tmp->email) ) {
				$error = TRUE;
				$errmsg = $this->lang('inv_clg_err_fill');
				break;
			}
			if( !is_valid_email($tmp->email) ) {
				$error = TRUE;
				$errmsg = $this->lang('inv_clg_err_email', array('#EMAIL#'=>htmlspecialchars($tmp->email)));
				break;
			}
		}
		if( !$error ) {
			$do_send_invites	= array();
			$ok_but_uexists	= array();
			$ok_but_alrdsent	= array();
			foreach($formdata as $tmp) {
				$db2->query('SELECT id, username, fullname FROM users WHERE email="'.$db2->e($tmp->email).'" LIMIT 1');
				if($u = $db2->fetch_object()) {
					$tmp->username	= stripslashes($u->username);
					$tmp->fullname	= stripslashes($u->fullname);
					$ok_but_uexists[]	= $tmp;
				}
				else {
					$db2->query('SELECT id FROM users_invitations WHERE user_id="'.$this->user->id.'" AND recp_email="'.$db2->e($tmp->email).'" LIMIT 1');
					if($db2->num_rows() > 0) {
						$ok_but_alrdsent[]	= $tmp;
					}
					else {
						$do_send_invites[]	= $tmp;
					}
				}
			}
			foreach($do_send_invites as $tmp) {
				$db2->query('INSERT INTO users_invitations SET user_id="'.$this->user->id.'", date="'.time().'", recp_name="'.$db2->e($tmp->name).'", recp_email="'.$db2->e($tmp->email).'", recp_is_registered=0, recp_user_id=0');
				$db1->query('SELECT * FROM unconfirmed_registrations WHERE email="'.$db1->e($tmp->email).'" LIMIT 1');
				if( $sdf = $db1->fetch_object() ) {
					$reg_id	= $sdf->id;
					$reg_key	= stripslashes($sdf->confirm_key);
					$db1->query('UPDATE unconfirmed_registrations SET fullname="'.$db1->e($tmp->name).'", date="'.time().'" WHERE id="'.$sdf->id.'" LIMIT 1');
				}
				else {
					$reg_key	= md5(rand().time().rand());
					$db1->query('REPLACE INTO unconfirmed_registrations SET email="'.$db1->e($tmp->email).'", fullname="'.$db1->e($tmp->name).'", confirm_key="'.$db1->e($reg_key).'", date="'.time().'" ');
					$reg_id	= intval($db1->insert_id());
				}
				$D->registration_link	= $C->SITE_URL.'signup/regid:'.$reg_id.'/regkey:'.$reg_key;
				$this->load_langfile('email/invite.php');
				$D->who	= $this->user->info->fullname;
				$D->whom	= $tmp->name;
				$D->lang_keys	= array('#WHO#'=>$D->who, '#WHOM#'=>$D->whom, '#COMPANY#'=>$C->COMPANY, '#SITE_TITLE#'=>$C->SITE_TITLE, '#SITE_URL#'=>$C->SITE_URL);
				$subject	= $this->lang('os_invite_email_subject', $D->lang_keys);
				$msgtxt	= $this->load_single_block('email/invite_txt.php', FALSE, TRUE);
				$msghtml	= $this->load_single_block('email/invite_html.php', FALSE, TRUE);
				$from	= $this->user->info->fullname.' <'.$this->user->info->email.'>';
				do_send_mail_html($tmp->email, $subject, $msgtxt, $msghtml, $from);
			}
			$nm	= count($do_send_invites);
			if( count($ok_but_uexists)==0 && count($ok_but_alrdsent)==0 ) {
				$okmsg	= $this->lang( 'inv_clg_ok_sent_'.($nm==0 ? '0' : ($nm==1 ? '1' : 'more')), array('#NUM#'=>$nm) );
			}
			else {
				$error = TRUE;
				$errmsg	= array();
				foreach($ok_but_uexists as $tmp) {
					$errmsg[]	= $this->lang('inv_clg_ok_exists', array('#NAME#'=>'<b>'.htmlspecialchars($tmp->name).'</b>', '#USERLINK#'=>'<a href="'.$C->SITE_URL.$tmp->username.'" title="'.htmlspecialchars($tmp->fullname).'">@'.$tmp->username.'</a>'));
				}
				foreach($ok_but_alrdsent as $tmp) {
					$errmsg[]	= $this->lang('inv_clg_ok_alrdsent', array('#EMAIL#'=>'<b>'.$tmp->email.'</b>'));
				}
				$errmsg	= '<div style="line-height:1.4; margin-top:5px;">'.implode('<br />', $errmsg).'</div>';
			}
		}
	}
	
	//TEMPLATE CODE START
	$tpl = new template( array('page_title' => $this->lang('os_invite_ttl_colleagues', array('#SITE_TITLE#'=>$C->SITE_TITLE, '#OUTSIDE_SITE_TITLE#'=>$C->OUTSIDE_SITE_TITLE)), 'header_page_layout'=>'c') );
	
	$tpl->initRoutine('InviteTopMenu', array());
	$tpl->routine->load();
	
	if( $submit && !$error ){
		$tpl->layout->setVar('main_content_placeholder', $tpl->designer->okMessage($this->lang('inv_clg_ok_msg'), $okmsg ) );
	}else if( $submit && $error ){
		$tpl->layout->setVar('main_content_placeholder', $tpl->designer->errorMessage($this->lang('inv_clg_error'), $errmsg) );
	}
	
	$table = new tableCreator();
	
	$rows = array(
			$table->inputField($this->lang('inv_clg_form_name'), 'name[]', ''),
			$table->inputField($this->lang('inv_clg_form_email'), 'email[]', ''),
			$table->submitButton( 'sbm', $this->lang('inv_clg_form_submit') )
	);
	$tpl->layout->setVar('main_content', $table->createTableInput( $rows ));
	
	$tpl->display();
?>