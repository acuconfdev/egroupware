<?php
/**************************************************************************\
* eGroupWare - TimeSheet: user interface                                   *
* http://www.eGroupWare.org                                                *
* Written and (c) 2005 by Ralf Becker <RalfBecker@outdoor-training.de>     *
* -------------------------------------------------------                  *
*  This program is free software; you can redistribute it and/or modify it *
*  under the terms of the GNU General Public License as published by the   *
*  Free Software Foundation; either version 2 of the License, or (at your  *
*  option) any later version.                                              *
\**************************************************************************/

/* $Id$ */

require_once(EGW_INCLUDE_ROOT.'/etemplate/inc/class.uietemplate.inc.php');
require_once('class.botimesheet.inc.php');

/**
 * User interface object of the TimeSheet
 *
 * @package timesheet
 * @author RalfBecker-AT-outdoor-training.de
 * @copyright (c) 2005 by RalfBecker-AT-outdoor-training.de
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */
class uitimesheet extends botimesheet
{
	var $public_functions = array(
		'view' => true,
		'edit' => true,
		'index' => true,
	);

	function uitimesheet()
	{
		$this->botimesheet();
	}

	function view()
	{
		$this->edit(null,true);
	}
	
	function edit($content = null,$view = false)
	{
		$tabs = 'general|notes|links';

		if (!is_array($content))
		{
			if ($view || (int)$_GET['ts_id'])
			{
				if (!$this->read((int)$_GET['ts_id']))
				{
					$GLOBALS['egw']->common->egw_header();
					echo "<script>alert('".lang('Permission denied!!!')."'); window.close();</script>\n";
					$GLOBALS['egw']->common->egw_exit();
				}
				if (!$view && !$this->check_acl(EGW_ACL_EDIT))
				{
					$view = true;
				}
			}
			else	// new entry
			{
				$this->data = array(
					'ts_start' => $this->today,
					'ts_owner' => $GLOBALS['egw_info']['user']['account_id'],
					'cat_id'   => (int) $_REQUEST['cat_id'],
				);
			}
			$referer = preg_match('/menuaction=([^&]+)/',$_SERVER['HTTP_REFERER'],$matches) ? $matches[1] : TIMESHEET_APP.'.uitimesheet.index';
		}
		else
		{
			list($button) = each($content['button']);
			$view = $content['view'];
			$referer = $content['referer'];
			$this->data = $content;
			foreach(array('button','view','referer',$tabs) as $key)
			{
				unset($this->data[$key]);
			}
			switch($button)
			{
				case 'edit':
					if ($this->check_acl(EGW_ACL_EDIT)) $view = false;
					break;
					
				case 'save':
				case 'save_new':
				case 'apply':
					if (!$this->data['ts_quantity'])	// set the quantity (in h) from the duration (in min)
					{
						$this->data['ts_quantity'] = $this->data['ts_duration'] / 60.0;
					}
					if (!$this->data['ts_project']) $this->data['ts_project'] = $this->data['ts_project_blur'];

					if ($this->save() != 0)
					{
						$msg = lang('Error saving the entry!!!');
						$button = '';
					}
					else
					{
						$msg = lang('Entry saved');
						if (is_array($content['link_to']['to_id']) && count($content['link_to']['to_id']))
						{
							$this->link->link(TIMESHEET_APP,$this->data['ts_id'],$content['link_to']['to_id']);
						}
					}
					$js = "opener.location.href='".$GLOBALS['egw']->link('/index.php',array(
						'menuaction' => $referer,
						'msg'        => $msg,
					))."';";
					if ($button == 'apply') break;
					if ($button == 'save_new')
					{
						// create a new entry
						$this->data['ts_start'] += 60 * $this->data['ts_duration'];
						foreach(array('ts_id','ts_title','ts_description','ts_duration','ts_quantity','ts_modified','ts_modifier') as $name)
						{
							unset($this->data[$name]);
						}
						break;
					}
					// fall-through for save
				case 'delete':
					if ($button == 'delete')
					{
						$this->delete();
						$msg = lang('Entry deleted');
						$js = "opener.location.href=opener.location.href+'&msg=$msg'";
					}
				case 'cancel':
					$js .= 'window.close();';
					echo "<html>\n<body>\n<script>\n$js\n</script>\n</body>\n</html>\n";
					$GLOBALS['egw']->common->egw_exit();
					break;
			}
		}
		$preserv = $this->data + array(
			'view'    => $view,
			'referer' => $referer,
		);
		$content = $this->data + array(
			'msg'  => $msg,
			'view' => $view,
			$tabs  => $content[$tabs],
			'link_to' => array(
				'to_id' => $content['link_to']['to_id'] ? $content['link_to']['to_id'] : $this->data['ts_id'],
				'to_app' => TIMESHEET_APP,
			),
			'js' => "<script>\n$js\n</script>\n",
			'ts_quantity_blur' => $this->data['ts_duration'] ? $this->data['ts_duration'] / 60.0 : '',
		);
		if (!$this->data['ts_id'] && isset($_GET['link_app']) && isset($_GET['link_id']) &&
			preg_match('/^[a-z_0-9-]+:[:a-z_0-9-]+$/i',$_GET['link_app'].':'.$_GET['link_id']) &&	// gard against XSS
			!is_array($content['link_to']['to_id']))
		{
			$this->link->link(TIMESHEET_APP,$content['link_to']['to_id'],$_GET['link_app'],$_GET['link_id']);
//			$content['ts_project'] = $this->link->title($_GET['link_app'],$_GET['link_id']);
			if ($_GET['link_app'] == 'projectmanager')
			{
				$links = array($_GET['link_id']);
			}
		}
		elseif ($this->data['ts_id'])
		{
			$links = $this->link->get_links(TIMESHEET_APP,$this->data['ts_id'],'projectmanager');
		}
		if ($links)
		{
			$preserv['ts_project_blur'] = $content['ts_project_blur'] = $this->link->title('projectmanager',array_shift($links));
		}
		$readonlys = array(
			'button[delete]'   => !$this->data['ts_id'] || !$this->check_acl(EGW_ACL_DELETE),
			'button[edit]'     => !$view || !$this->check_acl(EGW_ACL_EDIT),
			'button[save]'     => $view,
			'button[save_new]' => $view,
			'button[apply]'    => $view,
		);
		if ($view)
		{
			foreach($this->data as $key => $val)
			{
				$readonlys[$key] = true;
			}
		}
		$edit_grants = $this->grant_list(EGW_ACL_EDIT);
		if (count($edit_grants) == 1)
		{
			$readonlys['ts_owner'] = true;
		}
		$GLOBALS['egw_info']['flags']['app_header'] = lang('timesheet').' - '.
			($view ? lang('View') : ($this->data['ts_id'] ? lang('Edit') : lang('Add')));
		
		$etpl =& new etemplate('timesheet.edit');
		
		return $etpl->exec(TIMESHEET_APP.'.uitimesheet.edit',$content,array(
			'ts_owner' => $edit_grants,
		),$readonlys,$preserv,2);
	}

	/**
	 * query projects for nextmatch in the projects-list
	 *
	 * reimplemented from so_sql to disable action-buttons based on the acl and make some modification on the data
	 *
	 * @param array $query
	 * @param array &$rows returned rows/cups
	 * @param array &$readonlys eg. to disable buttons based on acl
	 */
	function get_rows($query,&$rows,&$readonlys)
	{
		$GLOBALS['egw']->session->appsession('index',TIMESHEET_APP,$query);

		unset($query['col_filter']['cat_id']);
		if ($query['cat_id'])
		{
			if (!is_object($GLOBALS['egw']->categories))
			{
				$GLOBALS['egw']->categories =& CreateObject('phpgwapi.categories');
			}
			$cats = $GLOBALS['egw']->categories->return_all_children((int)$query['cat_id']);
			$query['col_filter']['cat_id'] = count($cats) > 1 ? $cats : $query['cat_id'];
		}
		if ($query['filter'])
		{
			$query['col_filter'][0] = $this->date_filter($query['filter']);
		}
		if (!$query['col_filter']['ts_owner']) unset($query['col_filter']['ts_owner']);

		$total = parent::get_rows($query,$rows,$readonlys);
		
		unset($query['col_filter'][0]);
		
		$readonlys = array();
		foreach($rows as $n => $val)
		{
			$row =& $rows[$n];
			if (!$this->check_acl(EGW_ACL_EDIT,$row))
			{
				$readonlys["edit[$row[ts_id]]"] = true;
			}
			if (!$this->check_acl(EGW_ACL_DELETE,$row))
			{
				$readonlys["delete[$row[ts_id]]"] = true;
			}
			if ($query['col_filter']['ts_project'] || !$query['filter2'])
			{
				unset($row['ts_project']);	// dont need or want to show it
			}
			else
			{
				if (($links = $this->link->get_links(TIMESHEET_APP,$row['ts_id'],'projectmanager')))
				{
					$row['ts_link'] = array(
						'app' => 'projectmanager',
						'id'  => array_shift($links),
					);
				}
				$row['ts_link']['title'] = $row['ts_project'];
			}
			if (!$query['filter2'])
			{
				unset($row['ts_description']);
			}
		}
		$rows['no_owner_col'] = $query['no_owner_col'];
		if ($query['filter'])
		{
			$rows['duration'] = $this->summary['duration'];
			$rows['price'] = $this->summary['price'];
		}
		return $total;		
	}

	/**
	 * List timesheet entries
	 *
	 * @param array $content=null
	 */
	function index($content = null,$msg='')
	{
		$etpl =& new etemplate('timesheet.index');
		
		if ($_GET['msg']) $msg = $_GET['msg'];

		$content = array(
			'nm' => $GLOBALS['egw']->session->appsession('index',TIMESHEET_APP),
			'msg' => $msg,
		);		
		if (!is_array($content['nm']))
		{
			$date_filters = array('All');
			foreach($this->date_filters as $name => $date)
			{
				$date_filters[$name] = $name;
			}
			$content['nm'] = array(
				'get_rows'       =>	TIMESHEET_APP.'.uitimesheet.get_rows',
				'options-filter' => $date_filters,
				'options-filter2' => array('No details','Details'),
				'order'          =>	'ts_start',// IO name of the column to sort after (optional for the sortheaders)
				'sort'           =>	'DESC',// IO direction of the sort: 'ASC' or 'DESC'
			);
		}
		$read_grants = $this->grant_list(EGW_ACL_READ);
		$content['nm']['no_owner_col'] = count($read_grants) == 1;

		return $etpl->exec(TIMESHEET_APP.'.uitimesheet.index',$content,array(
			'ts_project' => $this->query_list('ts_project'),
			'ts_owner'   => $read_grants,
		),$readonlys,$preserv);
	}
}