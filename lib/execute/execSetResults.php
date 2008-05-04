<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 *
 * Filename $RCSfile: execSetResults.php,v $
 *
 * @version $Revision: 1.93 $
 * @modified $Date: 2008/05/04 10:33:33 $ $Author: franciscom $
 *
 * 20080224 - franciscom - to avoid performance problems
 *                         clicking on root node will NOT try to display
 *                         all testcases in testplan.
 *
 * 20080104 - franciscom - REQ 1232 - web editor on execution notes
 *                         added createExecNotesWebEditor()
 * 20071224 - franciscom - refactoring
 * 20071113 - franciscom - added contribution History for all builds.
 * 20071006 - franciscom - changes on exec_cfield_mgr() call
 * 20071002 - jbarchibald - BUGID 1051
 * 20070707 - franciscom - BUGID 921
 * 20070519 - franciscom - BUGID 856
 * 20070306 - franciscom - BUGID 705
 * 20070222 - franciscom - BUGID 647
 *
**/
require_once('../../config.inc.php');
require_once('common.php');
require_once('exec.inc.php');
require_once("builds.inc.php");
require_once("attachments.inc.php");
require_once("web_editor.php");
require("specview.php");

testlinkInitPage($db);

define('EXEC_PID_NOT_NEEDED',null);
define('EXEC_SHOW_ON_EXECUTION',1);

$templateCfg = templateConfiguration();

$cfg=getCfg();

$tcversion_id = null;
$submitResult = null;

$args = init_args();

$smarty = new TLSmarty();
$tree_mgr = new tree($db);
$tplan_mgr = new testplan($db);
$tcase_mgr = new testcase($db);
$exec_cfield_mgr = new exec_cfield_mgr($db,$args->tproject_id);
$attachmentRepository = tlAttachmentRepository::create($db);

$gui = initializeGui($db,$args,$cfg,$tplan_mgr,$tcase_mgr);
$_SESSION['history_on'] = $gui->history_on;

$do_show_instructions=(strlen($args->level) == 0 || $args->level=='testproject') ? 1 : 0;
if ($do_show_instructions)
{
    show_instructions('executeTest');
	  exit();
}

$attachmentInfos = null;

$get_mode = GET_ONLY_EXECUTED;
if(is_null($args->filter_status) || $args->filter_status == $cfg->tc_status['not_run'])
	$get_mode = GET_ALSO_NOT_EXECUTED;

// ---------------------------------------------------------
// Testplan executions and result archiving. Checks whether execute cases button was clicked
//
if($args->doExec == 1)
{
	/** @note Retrive testcase ids in an array */
	if(!is_null($args->tc_versions) && count($args->tc_versions))
	{
		$status_and_notes=do_remote_execution($db,$args->tc_versions);

		// Need to be added to $_REQUEST, because we are using $_REQUEST as input
		// for the function responsible of writing exec results. write_execution()
		$status_map = $status_and_notes['status'];
		$notes_map = $status_and_notes['notes'];

		if(count($status_map))
		{
			foreach($status_map as $key => $value)
			{
				$_REQUEST['status'][$key]=$value;  
				$_REQUEST['notes'][$key]=$notes_map[$key];  
			} 
		}
	   
	}
}	
// -----------------------------------------------------------
// When nullify filter_status - 20080504 - DO NOT REMOVE -
// 
// May be in the following situation we do not HAVE to apply filter status:
// 1. User have filter for Not Run on Tree
// 2. Clicks on TC XXX
// 3. Executes TC
// 4. DO NOT UPDATE TREE.
//    we do not update automatically to avoid:
//    a) performance problems
//    b) delays on operations due to tree redraw
//    c) loose tree status due to lack of feature of tree engine
//
// 5. Clicks again on TC XXX
// If we use filter, we will get No Data Available.
//
// When working on show_testsuite_contents mode (OLD MODE) when we show
// all testcases inside a testsuite that verifies a filter criteria WE NEED TO APPLY FILTER
//
// We do not have this problem when this page is called after user have executed,
// probably because filter_status is not send back.
//
// I will add logic to nullify filter_status on init_args()
// 
// 20080224 - franciscom - BUGID 1056
// 20070306 - franciscom - BUGID 705
// 20070914 - jbarchibald - added $cf_selected parameter
//
$linked_tcversions = $tplan_mgr->get_linked_tcversions($args->tplan_id,$args->tc_id,$args->keyword_id,$get_mode,
                                                       $args->filter_assigned_to,
                                                       $args->filter_status,$args->build_id,
                                                       $args->cf_selected,$args->include_unassigned);
$tcase_id = 0;
if(!is_null($linked_tcversions))
{
	  $items_to_exec = array();
	  $_SESSION['s_lastAttachmentInfos'] = null;
	  if($args->level == 'testcase')
    {
        list($tcase_id,$tcversion_id) = processTestCase($gui,$args,$cfg,$linked_tcversions,
                                                        $tree_mgr,$tcase_mgr,$attachmentRepository);
    }
    else
    {
        list($tcase_id,$tcversion_id) = processTestSuite($db,$gui,$args,$linked_tcversions,
                                                         $tree_mgr,$tcase_mgr,$attachmentRepository);
    }
    
    // will create a record even if the testcase version has not been executed (GET_NO_EXEC)
    $gui->map_last_exec=getLastExecution($db,$tcase_id,$tcversion_id,$gui,$args,$tcase_mgr);
    
    // --------------------------------------------------------------------------------------------
    // Results to DB
    if ($args->save_results || $args->do_bulk_save )
    {
    	$submitResult = write_execution($db,$args->user->dbID,$_REQUEST,$args->tproject_id,
    	                                $args->tplan_id,$args->build_id,$gui->map_last_exec);
    }

    if ($args->doDelete)
    {
    	delete_execution($db,$args->exec_to_delete);
    }
    // --------------------------------------------------------------------------------------------
    
    $gui->map_last_exec_any_build = null;
    if( $cfg->exec_cfg->show_last_exec_any_build )
    {
        $gui->map_last_exec_any_build = $tcase_mgr->get_last_execution($tcase_id,$tcversion_id,$args->tplan_id,
                                                                       ANY_BUILD,GET_NO_EXEC);
    }
    
    $gui->other_execs=getOtherExecutions($db,$tcase_id,$tcversion_id,$gui,$args,$cfg,$tcase_mgr);
   
    // Get attachment,bugs, etc
    if(!is_null($gui->other_execs))
    {
      $other_info=exec_additional_info($db,$attachmentRepository,$tcase_mgr,$gui->other_execs,$args->tplan_id);
 			$gui->attachments=$other_info['attachment'];
      $gui->bugs=$other_info['bugs'];
      $gui->other_exec_cfields=$other_info['cfexec_values'];
    }

}

$gui->exec_notes_editors=createExecNotesWebEditor($gui->map_last_exec,$_SESSION['basehref']);
smarty_assign_tsuite_info($smarty,$_REQUEST,$db,$tcase_id);

// To silence smarty errors
//  future must be initialized in a right way
$smarty->assign('test_automation_enabled',0);
$smarty->assign('cfg',$cfg);
$smarty->assign('gui',$gui);
$smarty->assign('g_bugInterface', $g_bugInterface);
$smarty->display($templateCfg->template_dir . $templateCfg->default_template);
?>

<?php
/*
  function: 

  args:
  
  returns: 
  
  rev:
      schlundus: changed the user_id to the currentUser of the session
*/
function init_args()
{
  $args = new stdClass();
 	$_REQUEST = strings_stripSlashes($_REQUEST);

	$args->doExec = isset($_REQUEST['execute_cases']) ? 1 : 0;
	$args->doDelete = isset($_REQUEST['do_delete']) ? 1 : 0;
	$args->cf_selected = isset($_REQUEST['cfields']) ? unserialize($_REQUEST['cfields']) : null;
	$args->tc_versions = isset($_REQUEST['tc_version']) ? $_REQUEST['tc_version'] : null;  
  
	$key2loop = array('level' => '','status' => null, 'do_bulk_save' => 0, 
	                  'save_results' => 0, 'filter_status' => null);
	foreach($key2loop as $key => $value)
	{
		$args->$key = isset($_REQUEST[$key]) ? $_REQUEST[$key] : $value;
	}

  // See details on: "When nullify filter_status - 20080504" in this file
  if( strlen(trim($args->filter_status)) == 0 or $args->level == 'testcase')
  {
      $args->filter_status = null;  
  }

  
  switch($args->level)
  {
      case 'testcase':
      $args->tc_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : null;
      $args->tsuite_id = null;
      break;
        
      case 'testsuite':
      $args->tsuite_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : null;
      $args->tc_id = null;
      break;
  }



	$key2loop = array('id' => 0,'build_id' =>0, 'exec_to_delete' => 0, 
				            'tpn_view_status' => 0, 'bn_view_status' => 0, 'bc_view_status' => 0, 
				            'filter_assigned_to' => null);
	foreach($key2loop as $key => $value)
	{
		$args->$key = isset($_REQUEST[$key]) ? intval($_REQUEST[$key]) : $value;
	}

  // 20080428 - franciscom
  if( isset($_REQUEST['keyword_id']) )
  {
     // can be a list
     $args->keyword_id=explode(',',$_REQUEST['keyword_id']);
     if( count($args->keyword_id) == 1)
     {
         $args->keyword_id=$args->keyword_id[0]; 
     }
  }
  else
  {
      $args->keyword_id=0;  
  }


  // Checkbox
  $args->include_unassigned=isset($_REQUEST['include_unassigned']) ? $_REQUEST['include_unassigned'] : 0;

	$args->tproject_id = $_SESSION['testprojectID'];
	$args->tplan_id = $_SESSION['testPlanId'];
	$args->user = $_SESSION['currentUser'];

	return $args;  
}


/*
  function: 

  args :
  
  returns: 

*/
function manage_history_on($hash_REQUEST,$hash_SESSION,
                           $exec_cfg,$btn_on_name,$btn_off_name,$hidden_on_name)
{
    if( isset($hash_REQUEST[$btn_on_name]) )
    {
		    $history_on = true;
    }
    elseif(isset($_REQUEST[$btn_off_name]))
    {
		    $history_on = false;
    }
    elseif (isset($_REQUEST[$hidden_on_name]))
    {
        $history_on = $_REQUEST[$hidden_on_name];
    }
    elseif (isset($_SESSION[$hidden_on_name]))
    {
        $history_on = $_SESSION[$hidden_on_name];
    }
    else
    {
        $history_on = $exec_cfg->history_on;
    }
    return $history_on;
}
/*
  function: get_ts_name_details

  args :
  
  returns: map with key=TCID
           values= assoc_array([tsuite_id => 5341
                               [details] => my detailas ts1
                               [tcid] => 5343
                               [tsuite_name] => ts1)
*/
function get_ts_name_details(&$db,$tcase_id)
{
	$rs = '';
	$do_query = true;
	$sql = "Select ts.id as tsuite_id, ts.details, 
	             nha.id as tc_id, nhb.name as tsuite_name 
	      FROM testsuites ts, nodes_hierarchy nha, nodes_hierarchy nhb
	      WHERE ts.id=nha.parent_id
	      AND   nhb.id=nha.parent_id ";
	if( is_array($tcase_id) && count($tcase_id) > 0)
	{
		$in_list = implode(",",$tcase_id);
		$sql .= "AND nha.id IN (" . $in_list . ")";
	}
	else if(!is_null($tcase_id))
	{
		$sql .= "AND nha.id={$tcase_id}";
	}
	else
	{
		$do_query = false;
	}
	if($do_query)
	{
		$rs = $db->fetchRowsIntoMap($sql,'tc_id');
	}
	
	return $rs;
}
/*
  function: 

  args :
  
  returns: 

*/
function smarty_assign_tsuite_info(&$smarty,&$request_hash, &$db,$tcase_id)
{

  $tsuite_info = get_ts_name_details($db,$tcase_id);
  $smarty->assign('tsuite_info',$tsuite_info);
  
  // --------------------------------------------------------------------------------
  if(!is_null($tsuite_info))
  {
    $a_tsvw=array();
    $a_ts=array();
    $a_tsval=array();
   
    $tsuite_mgr = New testsuite($db);
    
    foreach($tsuite_info as $key => $elem)
    {
      $main_k = 'tsdetails_view_status_' . $key;
      $a_tsvw[] = $main_k;
      $a_ts[] = 'tsdetails_' . $key;
      $a_tsval[] = isset($request_hash[$main_k]) ? $request_hash[$main_k] : 0;
   
      $tsuite_id = $elem['tsuite_id'];
      $tc_id = $elem['tc_id'];
      if(!isset($cached_cf[$tsuite_id]))
      {
         $cached_cf[$tsuite_id] = $tsuite_mgr->html_table_of_custom_field_values($tsuite_id);
      }
      $ts_cf_smarty[$tc_id] = $cached_cf[$tsuite_id];
   
    }
   
    $smarty->assign('tsd_div_id_list',implode(",",$a_ts));
    $smarty->assign('tsd_hidden_id_list',implode(",",$a_tsvw));
    $smarty->assign('tsd_val_for_hidden_list',implode(",",$a_tsval));
 
	  $smarty->assign('ts_cf_smarty',$ts_cf_smarty);
  }

}  
// --------------------------------------------------------------------------------


/*
  function: 

  args :
  
  returns: 

*/
function exec_additional_info(&$db,$attachmentRepository,&$tcase_mgr,$other_execs,$tplan_id)
{
  $bugInterfaceOn = config_get('bugInterfaceOn');
  $bugInterface = config_get('bugInterface');
  $attachmentInfos = null;
  $bugs = null;
  $cfexec_values = null;

  foreach($other_execs as $tcversion_id => $execInfo)
  {
    $num_elem = sizeof($execInfo);   
  	for($idx = 0;$idx < $num_elem;$idx++)
  	{
  		$exec_id = $execInfo[$idx]['execution_id'];
  		
  		$aInfo = getAttachmentInfos($attachmentRepository,$exec_id,'executions',true,1);
  		if ($aInfo)
  			$attachmentInfos[$exec_id] = $aInfo;
  		
  		if($bugInterfaceOn)
  		{
			$the_bugs = get_bugs_for_exec($db,$bugInterface,$exec_id);
			if(count($the_bugs) > 0)
				$bugs[$exec_id] = $the_bugs;
  		}

     
      // Custom fields
      $cfexec_values[$exec_id] = $tcase_mgr->html_table_of_custom_field_values($tcversion_id,'execution',null,
                                                                               $exec_id,$tplan_id);
  	}
  }
  
  $info = array( 'attachment' => $attachmentInfos,
                 'bugs' => $bugs,
                 'cfexec_values' => $cfexec_values);      
               
  return $info;
} //function end


/*
  function: 

  args :
  
  returns: 

*/
function do_remote_execution(&$db,$tc_versions)
{
  $tc_status = config_get('tc_status');
  $tree_mgr = new tree($db);
  $cfield_mgr = new cfield_mgr($db);
  
	$ret=array();
  $ret["status"]=array();
	$ret["notes"]=array();

	$executionResults = array();

	$myResult = array();
	foreach($tc_versions as $version_id => $tcase_id)
	{
		// RPC call
		$executionResults[$tcase_id] = executeTestCase($tcase_id,$tree_mgr,$cfield_mgr);
		if($executionResults){
			$myResult = $executionResults[$tcase_id]['result'];
			$myNotes = $executionResults[$tcase_id]['notes'];
			if ($myResult != -1 and $myNotes != -1) {
				$db_now = $db->db_now();
				$my_notes = $db->prepare_string(trim($myNotes));
				$my_result = strtolower($myResult);
				$my_result = $my_result{0};
				if( $my_result != $tc_status['passed'] && 
				    $my_result != $tc_status['failed'] && 
				    $my_result != $tc_status['blocked']){
					  $my_result = $tc_status['blocked'];
				}
				// 
				$ret["status"][$version_id] = $myResult;
				$ret["notes"][$version_id] = $my_notes;
				//
				$sql = "INSERT INTO executions (build_id,tester_id,status,testplan_id,tcversion_id,execution_ts,notes) ".
				       "VALUES ({$build_id},{$user_id},'{$my_result}',{$tplan_id},{$version_id},{$db_now},'{$my_notes}')";
				$db->exec_query($sql);
			}
		}

	}
	
	return $ret;
}


/*
  function: initializeExecMode 

  args:
  
  returns: 

*/
function initializeExecMode(&$db,$exec_cfg,$userObj,$tproject_id,$tplan_id)
{
    $effective_role = $userObj->getEffectiveRole($db,$tproject_id,$tplan_id);
    
	  // SCHLUNDUS: hmm, for user defined roles, this wont work correctly
	  // 20080104 - franciscom - Please explain why do you think will not work ok ?
	  //                         do you prefer to check for exec right ?
	  //
	  // SCHLUNDUS: jep, exactly. If a user defines it own roles than a check for the tester
	  // role will not do the desired effect of putting the logged in user in tester-view-mode
	  // instead we must check for presence (and or absence) the right(s) which mades a user a tester 
	  //
	  // 20080310 - franciscom - 
	  // Role is considered tester if:
	  // role == TL_ROLES_TESTER OR Role has Test Plan execute but not Test Plan planning
	  //
	  //
	  $can_execute = $effective_role->hasRight('testplan_execute');
	  $can_manage = $effective_role->hasRight('testplan_planning');
	  $use_exec_cfg = $effective_role->dbID == TL_ROLES_TESTER || ($can_execute && !$can_manage);

	  return  $use_exec_cfg ? $exec_cfg->exec_mode->tester : 'all';
} // function end


/*
  function: setTesterAssignment 

  args:
  
  returns: 

*/
function setTesterAssignment(&$db,$exec_info,&$tcase_mgr,$tplan_id)
{     
	foreach($exec_info as $version_id => $value)
	{
		$exec_info[$version_id]['assigned_user'] = '';
		$exec_info[$version_id]['assigned_user_id'] = 0;
		$p3 = $tcase_mgr->get_version_exec_assignment($version_id,$tplan_id);
		
		$assignedTesterId = intval($p3[$version_id]['user_id']);
		
		if($assignedTesterId)
		{
			$user = tlUser::getByID($db,$assignedTesterId);
			if ($user)
			{
				$exec_info[$version_id]['assigned_user']= $user->getDisplayName();  
			}
			$exec_info[$version_id]['assigned_user_id'] = $assignedTesterId;
		}  
	}
	return $exec_info;
} //function end

/*
  function: 
           Reorder executions to mantaing correct visualization order.

  args:
  
  returns: 

*/
function reorderExecutions(&$tcversion_id,&$exec_info)
{
    $dummy = array();
    foreach($tcversion_id as $key => $value)
    {
       $dummy[$key] = $exec_info[$value];    
    }
    return $dummy;    
}

/*
  function: setCanExecute 

  args:
  
  returns: 

*/
function setCanExecute($exec_info,$execution_mode,$can_execute,$tester_id)
{     
	foreach($exec_info as $key => $tc_exec) 
	{
		$execution_enabled = 0;  
		if($can_execute == 1 && $tc_exec['active'] == 1)
		{
			$assigned_to_me = $tc_exec['assigned_user_id'] == $tester_id ? 1 : 0;
			$is_free = $tc_exec['assigned_user_id'] == '' ? 1 : 0;

			switch($execution_mode)
			{
				case 'assigned_to_me':
					$execution_enabled = $assigned_to_me;
					break;

				case 'assigned_to_me_or_free':
					$execution_enabled = $assigned_to_me || $is_free;
					break;

				case 'all':
					$execution_enabled = 1;
					break;

				default:
					$execution_enabled = 0;  
					break;
			} // switch
		}
		$exec_info[$key]['can_be_executed']=$execution_enabled;
	}
	return $exec_info;
} //function end


/*
  function: createExecNotesWebEditor
            creates map of html needed to display web editors
            for execution notes.
            
  args: tcversions: array where each element has information
                    about testcase version that can be executed.
                    
        basehref: URL            
  
  returns: map
           key: testcase id
           value: html to display web editor.

  rev : 20080104 - creation  
*/
function createExecNotesWebEditor(&$tcversions,$basehref)
{
  
    if(is_null($tcversions) || count($tcversions) == 0 )
    {
        return null;  // nothing todo >>>------> bye!  
    }
     
    // Important Notice:
    //
    // When using tinymce or none as web editor, we need to set rows and cols
    // to appropriate values, to avoid an ugly ui.
    // null => use default values defined on editor class file
    //
    // Rows and Cols values are useless for FCKeditor.
    //
    //
    $a_oWebEditor_cfg = array('summary' => array('rows'=> null,'cols' => null),
                              'steps' => array('rows'=> null,'cols' => 38) ,
                              'expected_results' => array('rows'=> null,'cols' => 38));


    $idx=0;
    foreach($tcversions as $key => $tcv)
    {
        $tcversion_id=$tcv['id'];
        $tcase_id=$tcv['testcase_id'];

        $of=web_editor("notes[{$tcversion_id}]",$basehref) ;
        $of->Value = null;
        
        // Magic numbers that can be determined by trial and error
        $editors[$tcase_id]=$of->CreateHTML(10,60);         
        unset($of);
    }
    return $editors;
}



/*
  function: getCfg 

  args:
  
  returns: 

*/
function getCfg()
{
    $cfg = new stdClass();
    $cfg->exec_cfg = config_get('exec_cfg');
    $cfg->gui_cfg = config_get('gui');
    
    $results = config_get('results');
    $cfg->tc_status = $results['status_code'];
     
    $cfg->testcase_cfg = config_get('testcase_cfg'); 
    
    return $cfg;
}


/*
  function: initializeRights 
            create object with rights useful for this feature 
  
  args:
       dbHandler: reference to db object
       user_id:
       tproject_id:
       tplan_id
  
       Warning: this is right interface for this function, but
                has_rights() can works in a mode (that i consider a dirty one)
                using SESSION to achieve global coupling.
                 
  returns: 

*/
function initializeRights(&$dbHandler,$user_id,$tproject_id,$tplan_id)
{
    $exec_cfg=config_get('exec_cfg');
    $grants = new stdClass();
    $grants->execute = (has_rights($dbHandler,"testplan_execute")=="yes" ? 1 : 0);
    
    // may be in the future this can be converted to a role right
    $grants->delete_execution=$exec_cfg->can_delete_execution;
    
    
    // may be in the future this can be converted to a role right
    // Important:
    // Execution right must be present to consider this configuration option.
    $grants->edit_exec_notes=$grants->execute && $exec_cfg->edit_notes;
    
    return $grants;
}


/*
  function: initializeGui

  args :
  
  returns: 

  rev: 20080429 - franciscom
*/
function initializeGui(&$dbHandler,&$argsObj,&$cfgObj,&$tplanMgr,&$tcaseMgr)
{
    $gui = new stdClass();
    
    $buildMgr = new build_mgr($dbHandler);

    $gui->ownerDisplayName = null;
    $gui->filter_assigned_to=$argsObj->filter_assigned_to;
    $gui->tester_id=$argsObj->user->dbID;
    $gui->include_unassigned=$argsObj->include_unassigned;
 		$gui->attachmentInfos=null;
    $gui->bugs=null;
    $gui->other_exec_cfields=null;
    $gui->tpn_view_status=$argsObj->tpn_view_status;
    $gui->bn_view_status=$argsObj->bn_view_status;
    $gui->bc_view_status=$argsObj->bc_view_status;
    
    
    // $gui->default_status=config_get('tc_status_for_ui_default');

    $build_info = $buildMgr->get_by_id($argsObj->build_id);
    $gui->build_notes=$build_info['notes'];
    $gui->build_is_open=($build_info['is_open'] == 1 ? 1 : 0);
    
    $gui->execution_types=$tcaseMgr->get_execution_types();
    $gui->alluserInfo = tlUser::getAll($dbHandler,null,'id');




    if ($argsObj->filter_assigned_to)
    {
    	$user = tlUser::getById($dbHandler,$argsObj->filter_assigned_to);
    	if ($user)
    		$gui->ownerDisplayName = $user->getDisplayName();
    }

    $the_builds = $tplanMgr->get_builds_for_html_options($argsObj->tplan_id);
    $gui->build_name = isset($the_builds[$argsObj->build_id]) ? $the_builds[$argsObj->build_id] : '';


    $gui->grants = initializeRights($dbHandler,$argsObj->user->dbID,$argsObj->tproject_id,$argsObj->tplan_id);
    $gui->exec_mode = initializeExecMode($dbHandler,$cfgObj->exec_cfg,
                                         $argsObj->user,$argsObj->tproject_id,$argsObj->tplan_id);



    $rs = $tplanMgr->get_by_id($argsObj->tplan_id);
    $gui->testplan_notes = $rs['notes'];
    $gui->testplan_cfields = $tplanMgr->html_table_of_custom_field_values($argsObj->tplan_id,'execution',
                                                                          FILTER_BY_SHOW_ON_EXECUTION);

    $gui->history_on = manage_history_on($_REQUEST,$_SESSION,$cfgObj->exec_cfg,
                                         'btn_history_on','btn_history_off','history_on');
    $gui->history_status_btn_name = $gui->history_on ? 'btn_history_off' : 'btn_history_on';

    return $gui;
}


/*
  function: processTestCase

  args :
  
  returns: 

  rev: 
*/
function processTestCase(&$guiObj,&$argsObj,&$cfgObj,$linked_tcversions,&$treeMgr,&$tcaseMgr,&$docRepository)
{     
      $guiObj->design_time_cfields='';
  		
  		$items_to_exec[$argsObj->id] = $linked_tcversions[$argsObj->id]['tcversion_id'];    
  		$tcase_id = $argsObj->id;
  		$tcversion_id = $linked_tcversions[$argsObj->id]['tcversion_id'];
  		$guiObj->tcAttachments[$argsObj->id] = getAttachmentInfos($docRepository,$argsObj->id,'nodes_hierarchy',1);
      $guiObj->tcasePrefix=$tcaseMgr->getPrefix($tcase_id) . $cfgObj->testcase_cfg->glue_character;
      
      
  		$guiObj->design_time_cfields[$argsObj->id] = 
  		         $tcaseMgr->html_table_of_custom_field_values($argsObj->id,'design',EXEC_SHOW_ON_EXECUTION);
  		
      // BUGID 856: Guest user can execute test case
  		if($guiObj->grants->execute)
  		{
  		   $guiObj->execution_time_cfields[$argsObj->id] = 
  		            $tcaseMgr->html_table_of_custom_field_inputs($argsObj->id,EXEC_PID_NOT_NEEDED,
  		                                                         'execution',"_{$argsObj->id}");
  		}
  		
      // 20070405 - BUGID 766
      $tc_info=$treeMgr->get_node_hierachy_info($tcase_id);
	    $guiObj->tSuiteAttachments[$tc_info['parent_id']] = getAttachmentInfos($docRepository,$tc_info['parent_id'],
		                                                                        'nodes_hierarchy',true,1);
		                                                                        
      return array($tcase_id,$tcversion_id);
}




/*
  function: getLastExecution

  args :
  
  returns: 

  rev: 
*/
function getLastExecution(&$dbHandler,$tcase_id,$tcversion_id,$guiObj,$argsObj,&$tcaseMgr)
{      
    $last_exec = $tcaseMgr->get_last_execution($tcase_id,$tcversion_id,$argsObj->tplan_id,
                                               $argsObj->build_id,GET_NO_EXEC);

    if( !is_null($last_exec) )
    {
      $last_exec=setTesterAssignment($dbHandler,$last_exec,
                                     $tcaseMgr,$argsObj->tplan_id);
    
      // Warning: setCanExecute() must be called AFTER setTesterAssignment()  
      $can_execute=$guiObj->grants->execute && ($guiObj->build_is_open);
      $last_exec=setCanExecute($last_exec,$guiObj->exec_mode,
                               $can_execute,$argsObj->user->dbID);
    }
    
    // Reorder executions to mantaing correct visualization order.
    if( is_array($tcversion_id) )
    {
      $last_exec=reorderExecutions($tcversion_id,$last_exec);
    }
    return $last_exec;
}



/*
  function: getOtherExecutions

  args :
  
  returns: 

  rev: 
*/
function getOtherExecutions(&$dbHandler,$tcase_id,$tcversion_id,$guiObj,$argsObj,&$cfgObj,&$tcaseMgr)
{      
    $other_execs = null;
    if($guiObj->history_on)
    {
      // 20071113 - Contribution
      $build_id_filter=$argsObj->build_id;
      if($cfgObj->exec_cfg->show_history_all_builds )
      {
        $build_id_filter=ANY_BUILD;
      }  
      $other_execs = $tcaseMgr->get_executions($tcase_id,$tcversion_id,
                                               $argsObj->tplan_id,$build_id_filter,
                                               $cfgObj->exec_cfg->history_order);
    }    
    else
    {
        // Warning!!!:
        // we can't use the data we have got with previous call to get_last_execution()
        // because if user have asked to save results last execution data may be has changed
        $aux_map = $tcaseMgr->get_last_execution($tcase_id,$tcversion_id,$argsObj->tplan_id,$argsObj->build_id);

        if(!is_null($aux_map))
        {
            $other_execs = array();
            foreach($aux_map as $key => $value )
            {
               $other_execs[$key] = array($value);
            }
        }
    }
    
    return $other_execs;
}


/*
  function: processTestSuite

  args :
  
  returns: 

  rev: 
*/
function processTestSuite(&$dbHandler,&$guiObj,&$argsObj,$linked_tcversions,
                          &$treeMgr,&$tcaseMgr,&$docRepository)
{
    $testSet=new stdClass();
    
    $tsuite_mgr=new testsuite($dbHandler); 
    $tsuite_data = $tsuite_mgr->get_by_id($argsObj->id);
    
    $out = gen_spec_view($dbHandler,'testplan',$argsObj->tplan_id,$argsObj->id,$tsuite_data['name'],
                         $linked_tcversions,null,$argsObj->keyword_id,
                         FILTER_BY_TC_OFF,WRITE_BUTTON_ONLY_IF_LINKED,DO_PRUNE);
                         
    $testSet->tcase_id = array();
    $testSet->tcversion_id = array();
    foreach($out['spec_view'] as $key => $value)
    {
     if( count($value['testcases']) > 0 )
     {
       foreach($value['testcases'] as $xkey => $xvalue)
       {
         $testSet->tcase_id[]=$xkey;
         $testSet->tcversion_id[]=$xvalue['linked_version_id'];
       }  
     }
    }
    $guiObj->tcasePrefix=$tcaseMgr->getPrefix($testSet->tcase_id[0]);
    // ---------------------------------------------------------------------------------
    
    // Get the path for every test case, grouping test cases that
    // have same parent.
    $idx = 0;
    foreach($linked_tcversions as $item)
    {
    	$path_f = $treeMgr->get_path($item['tc_id'],null,'full');
    	foreach($path_f as $key => $path_elem)
    	{
    		if( $path_elem['parent_id'] == $argsObj->id )
    		{
    		 // Can be added because is present in the branch the user wants to view
    		 // ID of branch starting node is in $argsObj->id
    		 $guiObj->tcAttachments[$item['tc_id']] = getAttachmentInfos($docRepository,$item['tc_id'],
    		                                                             'nodes_hierarchy',true,1);
    
         // --------------------------------------------------------------------------------------
    		 $guiObj->design_time_cfields[$item['tc_id']] = $tcaseMgr->html_table_of_custom_field_values($item['tc_id'],
    				                                                                       'design',EXEC_SHOW_ON_EXECUTION);
    				                                                                        
         // BUGID 856: Guest user can execute test case
    		 if($guiObj->grants->execute)
    	   {
    				$guiObj->execution_time_cfields[$item['tc_id']] = 
    				         $tcaseMgr->html_table_of_custom_field_inputs($item['tc_id'], EXEC_PID_NOT_NEEDED,'execution',
    				                                                      "_".$item['tc_id']);
         }
    		} // if( $path_elem['parent_id'] == $argsObj->id )
    		
    	  if($path_elem['node_table'] == 'testsuites' && !isset($guiObj->tSuiteAttachments[$path_elem['id']]))
    		   $guiObj->tSuiteAttachments[$path_elem['id']] = getAttachmentInfos($docRepository,$path_elem['id'],
    		                                                             'nodes_hierarchy',true,1);
    		   
      } //foreach($path_f as $key => $path_elem) 
    } // foreach($linked_tcversions as $item)

    return array($testSet->tcase_id,$testSet->tcversion_id);  
}
?>																																