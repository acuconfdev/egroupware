<?php
/**
 * EGroupware: CalDAV/CardDAV/GroupDAV access: Addressbook handler
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package addressbook
 * @subpackage carddav
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright (c) 2007-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Acl;

/**
 * CalDAV/CardDAV/GroupDAV access: Addressbook handler
 *
 * Propfind now uses a Api\CalDAV\PropfindIterator with a callback to query huge addressbooks in chunk,
 * without getting into problems with memory_limit.
 *
 * Permanent error_log() calls should use $this->caldav->log($str) instead, to be send to PHP error_log()
 * and our request-log (prefixed with "### " after request and response, like exceptions).
 */
class addressbook_groupdav extends Api\CalDAV\Handler
{
	/**
	 * bo class of the application
	 *
	 * @var Api\Contacts
	 */
	var $bo;

	var $filter_prop2cal = array(
		'UID' => 'uid',
		//'NICKNAME',
		'EMAIL' => 'email',
		'FN' => 'n_fn',
		'ORG' => 'org_name',
	);

	/**
	 * Charset for exporting data, as some clients ignore the headers specifying the charset
	 *
	 * @var string
	 */
	var $charset = 'utf-8';

	/**
	 * 'addressbook_home_set' preference already exploded as array
	 *
	 * A = all available addressbooks
	 * G = primary group
	 * D = distribution lists as groups
	 * O = sync all in one (/<username>/addressbook/)
	 * or nummerical account_id, but not user itself
	 *
	 * @var array
	 */
	var $home_set_pref;

	/**
	 * Constructor
	 *
	 * @param string $app 'calendar', 'addressbook' or 'infolog'
	 * @param Api\CalDAV $caldav calling class
	 */
	function __construct($app, Api\CalDAV $caldav)
	{
		parent::__construct($app, $caldav);

		$this->bo = new Api\Contacts();

		// since 1.9.007 we allow clients to specify the URL when creating a new contact, as specified by CardDAV
		// LDAP does NOT have a carddav_name attribute --> stick with id mapped to LDAP attribute uid
		if (version_compare($GLOBALS['egw_info']['apps']['phpgwapi']['version'], '1.9.007', '<') ||
			$this->bo->contact_repository != 'sql' ||
			$this->bo->account_repository != 'sql' && strpos($_SERVER['REQUEST_URI'].'/','/addressbook-accounts/') !== false)
		{
			self::$path_extension = '.vcf';
		}
		else
		{
			self::$path_attr = 'carddav_name';
			self::$path_extension = '';
		}
		if ($this->debug) error_log(__METHOD__."() contact_repository={$this->bo->contact_repository}, account_repository={$this->bo->account_repository}, REQUEST_URI=$_SERVER[REQUEST_URI] --> path_attr=".self::$path_attr.", path_extension=".self::$path_extension);

		$this->home_set_pref = $GLOBALS['egw_info']['user']['preferences']['groupdav']['addressbook-home-set'];
		$this->home_set_pref = $this->home_set_pref ? explode(',',$this->home_set_pref) : array();

		// silently switch "Sync all into one" preference on for OS X addressbook, as it only supports one AB
		// this restores behavior before Lion (10.7), where AB synced all ABs contained in addressbook-home-set
		if (substr(self::get_agent(),0,9) == 'cfnetwork' && !in_array('O',$this->home_set_pref))
		{
			$this->home_set_pref[] = 'O';
		}
	}

	/**
	 * Handle propfind in the addressbook folder
	 *
	 * @param string $path
	 * @param array &$options
	 * @param array &$files
	 * @param int $user account_id
	 * @param string $id =''
	 * @return mixed boolean true on success, false on failure or string with http status (eg. '404 Not Found')
	 */
	function propfind($path,&$options,&$files,$user,$id='')
	{
		$filter = array();
		// If "Sync selected addressbooks into one" is set
		if ($user && $user == $GLOBALS['egw_info']['user']['account_id'] && in_array('O',$this->home_set_pref))
		{
			$filter['owner'] = array_keys($this->get_shared(true));	// true: ignore all-in-one pref
			$filter['owner'][] = $user;
		}
		// show addressbook of a single user?
		elseif ($user && $path != '/addressbook/' || $user === 0)
		{
			$filter['owner'] = $user;
		}
		// should we hide the accounts addressbook
		if ($GLOBALS['egw_info']['user']['preferences']['addressbook']['hide_accounts'] === '1') $filter['account_id'] = null;

		// process REPORT filters or multiget href's
		$nresults = null;
		if (($id || $options['root']['name'] != 'propfind') && !$this->_report_filters($options,$filter,$id, $nresults))
		{
			return false;
		}
		if ($id) $path = dirname($path).'/';	// carddav_name get's added anyway in the callback

		if ($this->debug) error_log(__METHOD__."($path,".array2string($options).",,$user,$id) filter=".array2string($filter));

		// check if we have to return the full contact data or just the etag's
		if (!($filter['address_data'] = $options['props'] == 'all' &&
			$options['root']['ns'] == Api\CalDAV::CARDDAV) && is_array($options['props']))
		{
			foreach($options['props'] as $prop)
			{
				if ($prop['name'] == 'address-data')
				{
					$filter['address_data'] = true;
					break;
				}
			}
		}
		// rfc 6578 sync-collection report: filter for sync-token is already set in _report_filters
		if ($options['root']['name'] == 'sync-collection')
		{
			// callback to query sync-token, after propfind_callbacks / iterator is run and
			// stored max. modification-time in $this->sync_collection_token
			$files['sync-token'] = array($this, 'get_sync_collection_token');
			$files['sync-token-params'] = array($path, $user);

			$this->sync_collection_token = null;

			$filter['order'] = 'contact_modified ASC';	// return oldest modifications first
			$filter['sync-collection'] = true;
		}

		if (isset($nresults))
		{
			$files['files'] = $this->propfind_callback($path, $filter, array(0, (int)$nresults));

			// hack to support limit with sync-collection report: contacts are returned in modified ASC order (oldest first)
			// if limit is smaller then full result, return modified-1 as sync-token, so client requests next chunk incl. modified
			// (which might contain further entries with identical modification time)
			if ($options['root']['name'] == 'sync-collection' && $this->bo->total > $nresults)
			{
				--$this->sync_collection_token;
				$files['sync-token-params'][] = true;	// tel get_sync_collection_token that we have more entries
			}
		}
		else
		{
			// return iterator, calling ourself to return result in chunks
			$files['files'] = new Api\CalDAV\PropfindIterator($this,$path,$filter,$files['files']);
		}
		return true;
	}

	/**
	 * Callback for profind iterator
	 *
	 * @param string $path
	 * @param array& $filter
	 * @param array|boolean $start =false false=return all or array(start,num)
	 * @return array with "files" array with values for keys path and props
	 */
	function &propfind_callback($path,array &$filter,$start=false,$report_not_found_multiget_ids=true)
	{
		//error_log(__METHOD__."('$path', ".array2string($filter).", ".array2string($start).", $report_not_found_multiget_ids)");
		$starttime = microtime(true);
		$filter_in = $filter;

		if (($address_data = $filter['address_data']))
		{
			$handler = self::_get_handler();
		}
		unset($filter['address_data']);

		if (isset($filter['order']))
		{
			$order = $filter['order'];
			unset($filter['order']);
		}
		else
		{
			$order = 'egw_addressbook.contact_id';
		}
		// detect sync-collection report
		$sync_collection_report = $filter['sync-collection'];
		unset($filter['sync-collection']);

		if (isset($filter[self::$path_attr]))
		{
			if (!is_array($filter[self::$path_attr])) $filter[self::$path_attr] = (array)$filter[self::$path_attr];
			$requested_multiget_ids =& $filter[self::$path_attr];
		}

		$files = array();
		// we query etag and modified, as LDAP does not have the strong sql etag
		$cols = array('id','uid','etag','modified','n_fn');
		if (!in_array(self::$path_attr,$cols)) $cols[] = self::$path_attr;
		// we need tid for sync-collection report
		if (array_key_exists('tid', $filter) && !isset($filter['tid']) && !in_array('tid', $cols)) $cols[] = 'tid';
		if (($contacts =& $this->bo->search(array(),$cols,$order,'','',False,'AND',$start,$filter)))
		{
			foreach($contacts as &$contact)
			{
				// remove contact from requested multiget ids, to be able to report not found urls
				if ($requested_multiget_ids && ($k = array_search($contact[self::$path_attr], $requested_multiget_ids)) !== false)
				{
					unset($requested_multiget_ids[$k]);
				}
				// sync-collection report: deleted entry need to be reported without properties
				if ($contact['tid'] == Api\Contacts::DELETED_TYPE)
				{
					$files[] = array('path' => $path.urldecode($this->get_path($contact)));
					continue;
				}
				$props = array(
					'getcontenttype' => Api\CalDAV::mkprop('getcontenttype', 'text/vcard'),
					'getlastmodified' => $contact['modified'],
					'displayname' => $contact['n_fn'],
				);
				if ($address_data)
				{
					$content = $handler->getVCard($contact['id'],$this->charset,false);
					$props['getcontentlength'] = bytes($content);
					$props[] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'address-data',$content,true);
				}
				$files[] = $this->add_resource($path, $contact, $props);
			}
			// sync-collection report --> return modified of last contact as sync-token
			if ($sync_collection_report)
			{
				$this->sync_collection_token = $contact['modified'];
			}
		}
		// last chunk or no chunking: add accounts from different repo and report missing multiget urls
		if (!$start || count($contacts) < $start[1])
		{
			//error_log(__METHOD__."('$path', ".array2string($filter).", ".array2string($start)."; $report_not_found_multiget_ids) last chunk detected: count()=".count($contacts)." < $start[1]");
			// add accounts after contacts, if enabled and stored in different repository
			if ($this->bo->so_accounts && is_array($filter['owner']) && in_array('0', $filter['owner']))
			{
				$accounts_filter = $filter_in;
				$accounts_filter['owner'] = '0';
				if ($sync_collection_report) $token_was = $this->sync_collection_token;
				self::$path_attr = 'id';
				self::$path_extension = '.vcf';
				$files = array_merge($files, $this->propfind_callback($path, $accounts_filter, false, false));
				self::$path_attr = 'carddav_name';
				self::$path_extension = '';
				if ($sync_collection_report && $token_was > $this->sync_collection_token)
				{
					$this->sync_collection_token = $token_was;
				}
			}
			// add groups after contacts, but only if enabled and NOT for '/addressbook/' (!isset($filter['owner'])
			if (in_array('D',$this->home_set_pref) && (string)$filter['owner'] !== '0')
			{
				$where = array(
					'list_owner' => isset($filter['owner'])?$filter['owner']:array_keys($this->bo->grants)
				);
				// add sync-token to support sync-collection report
				if ($sync_collection_report)
				{
					list(,$sync_token) = explode('>', $filter[0]);
					if ((int)$sync_token) $where[] = 'list_modified>'.$GLOBALS['egw']->db->from_unixtime((int)$sync_token);
				}
				if (isset($filter[self::$path_attr]))	// multiget report?
				{
					$where['list_'.self::$path_attr] = $filter[self::$path_attr];
				}
				//error_log(__METHOD__."() filter=".array2string($filter).", do_groups=".in_array('D',$this->home_set_pref).", where=".array2string($where));
				if ($where['id'] && ($lists = $this->bo->read_lists($where,'contact_uid',$where['list_owner'])))	// limit to contacts in same AB!
				{
					foreach($lists as $list)
					{
						$list[self::$path_attr] = $list['list_carddav_name'];
						$etag = $list['list_id'].':'.$list['list_etag'];
						// for all-in-one addressbook, add selected ABs to etag
						if (isset($filter['owner']) && is_array($filter['owner']))
						{
							$etag .= ':'.implode('-',$filter['owner']);
						}
						$props = array(
							'getcontenttype' => Api\CalDAV::mkprop('getcontenttype', 'text/vcard'),
							'getlastmodified' => Api\DateTime::to($list['list_modified'],'ts'),
							'displayname' => $list['list_name'],
							'getetag' => '"'.$etag.'"',
						);
						if ($address_data)
						{
							$content = $handler->getGroupVCard($list);
							$props['getcontentlength'] = bytes($content);
							$props[] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'address-data',$content,true);
						}
						$files[] = $this->add_resource($path, $list, $props);

						// remove list from requested multiget ids, to be able to report not found urls
						if ($requested_multiget_ids && ($k = array_search($list[self::$path_attr], $requested_multiget_ids)) !== false)
						{
							unset($requested_multiget_ids[$k]);
						}

						if ($sync_collection_report && $this->sync_collection_token < ($ts=$GLOBALS['egw']->db->from_timestamp($list['list_modified'])))
						{
							$this->sync_collection_token = $ts;
						}
					}
				}
			}
			// report not found multiget urls
			if ($report_not_found_multiget_ids && $requested_multiget_ids)
			{
				foreach($requested_multiget_ids as $id)
				{
					$files[] = array('path' => $path.$id.self::$path_extension);
				}
			}
		}

		if ($this->debug) error_log(__METHOD__."($path,".array2string($filter).','.array2string($start).") took ".(microtime(true) - $starttime).' to return '.count($files).' items');
		return $files;
	}

	/**
	 * Process the filters from the CalDAV REPORT request
	 *
	 * @param array $options
	 * @param array &$cal_filters
	 * @param string $id
	 * @param int &$nresult on return limit for number or results or unchanged/null
	 * @return boolean true if filter could be processed
	 */
	function _report_filters($options,&$filters,$id, &$nresults)
	{
		if ($options['filters'])
		{
			/* Example of a complex filter used by Mac Addressbook
			  <B:filter test="anyof">
			    <B:prop-filter name="FN" test="allof">
			      <B:text-match collation="i;unicode-casemap" match-type="contains">becker</B:text-match>
			      <B:text-match collation="i;unicode-casemap" match-type="contains">ralf</B:text-match>
			    </B:prop-filter>
			    <B:prop-filter name="EMAIL" test="allof">
			      <B:text-match collation="i;unicode-casemap" match-type="contains">becker</B:text-match>
			      <B:text-match collation="i;unicode-casemap" match-type="contains">ralf</B:text-match>
			    </B:prop-filter>
			    <B:prop-filter name="NICKNAME" test="allof">
			      <B:text-match collation="i;unicode-casemap" match-type="contains">becker</B:text-match>
			      <B:text-match collation="i;unicode-casemap" match-type="contains">ralf</B:text-match>
			    </B:prop-filter>
			  </B:filter>
			*/
			$filter_test = isset($options['filters']['attrs']) && isset($options['filters']['attrs']['test']) ?
				$options['filters']['attrs']['test'] : 'anyof';
			$prop_filters = array();

			$matches = $prop_test = $column = null;
			foreach($options['filters'] as $n => $filter)
			{
				if (!is_int($n)) continue;	// eg. attributes of filter xml element

				switch((string)$filter['name'])
				{
					case 'param-filter':
						$this->caldav->log(__METHOD__."(...) param-filter='{$filter['attrs']['name']}' not (yet) implemented!");
						break;
					case 'prop-filter':	// can be multiple prop-filter, see example
						if ($matches) $prop_filters[] = implode($prop_test=='allof'?' AND ':' OR ',$matches);
						$matches = array();
						$prop_filter = strtoupper($filter['attrs']['name']);
						$prop_test = isset($filter['attrs']['test']) ? $filter['attrs']['test'] : 'anyof';
						if ($this->debug > 1) error_log(__METHOD__."(...) prop-filter='$prop_filter', test='$prop_test'");
						break;
					case 'is-not-defined':
						$matches[] = '('.$column."='' OR ".$column.' IS NULL)';
						break;
					case 'text-match':	// prop-filter can have multiple text-match, see example
						if (!isset($this->filter_prop2cal[$prop_filter]))	// eg. not existing NICKNAME in EGroupware
						{
							if ($this->debug || $prop_filter != 'NICKNAME') error_log(__METHOD__."(...) text-match: $prop_filter {$filter['attrs']['match-type']} '{$filter['data']}' unknown property '$prop_filter' --> ignored");
							$column = false;	// to ignore following data too
						}
						else
						{
							switch($filter['attrs']['collation'])	// todo: which other collations allowed, we are allways unicode
							{
								case 'i;unicode-casemap':
								default:
									$comp = ' '.$GLOBALS['egw']->db->capabilities[Api\Db::CAPABILITY_CASE_INSENSITIV_LIKE].' ';
									break;
							}
							$column = $this->filter_prop2cal[strtoupper($prop_filter)];
							if (strpos($column, '_') === false) $column = 'contact_'.$column;
							if (!isset($filters['order'])) $filters['order'] = $column;
							$match_type = $filter['attrs']['match-type'];
							$negate_condition = isset($filter['attrs']['negate-condition']) && $filter['attrs']['negate-condition'] == 'yes';
						}
						break;
					case '':	// data of text-match element
						if (isset($filter['data']) && isset($column))
						{
							if ($column)	// false for properties not known to EGroupware
							{
								$value = str_replace(array('%', '_'), array('\\%', '\\_'), $filter['data']);
								switch($match_type)
								{
									case 'equals':
										$sql_filter = $column . $comp . $GLOBALS['egw']->db->quote($value);
										break;
									default:
									case 'contains':
										$sql_filter = $column . $comp . $GLOBALS['egw']->db->quote('%'.$value.'%');
										break;
									case 'starts-with':
										$sql_filter = $column . $comp . $GLOBALS['egw']->db->quote($value.'%');
										break;
									case 'ends-with':
										$sql_filter = $column . $comp . $GLOBALS['egw']->db->quote('%'.$value);
										break;
								}
								$matches[] = ($negate_condition ? 'NOT ' : '').$sql_filter;

								if ($this->debug > 1) error_log(__METHOD__."(...) text-match: $prop_filter $match_type' '{$filter['data']}'");
							}
							unset($column);
							break;
						}
						// fall through
					default:
						$this->caldav->log(__METHOD__."(".array2string($options).",,$id) unknown filter=".array2string($filter).' --> ignored');
						break;
				}
			}
			if ($matches) $prop_filters[] = implode($prop_test=='allof'?' AND ':' OR ',$matches);
			if ($prop_filters)
			{
				$filters[] = $filter = '(('.implode($filter_test=='allof'?') AND (':') OR (', $prop_filters).'))';
				if ($this->debug) error_log(__METHOD__."(path=$options[path], ...) sql-filter: $filter");
			}
		}
		// parse limit from $options['other']
		/* Example limit
		  <B:limit>
		    <B:nresults>10</B:nresults>
		  </B:limit>
		*/
		foreach((array)$options['other'] as $option)
		{
			switch($option['name'])
			{
				case 'nresults':
					$nresults = (int)$option['data'];
					//error_log(__METHOD__."(...) options[other]=".array2string($options['other'])." --> nresults=$nresults");
					break;
				case 'limit':
					break;
				case 'href':
					break;	// from addressbook-multiget, handled below
				// rfc 6578 sync-report
				case 'sync-token':
					if (!empty($option['data']))
					{
						$parts = explode('/', $option['data']);
						$sync_token = array_pop($parts);
						$filters[] = 'contact_modified>'.(int)$sync_token;
						$filters['tid'] = null;	// to return deleted entries too
					}
					break;
				case 'sync-level':
					if ($option['data'] != '1')
					{
						$this->caldav->log(__METHOD__."(...) only sync-level {$option['data']} requested, but only 1 supported! options[other]=".array2string($options['other']));
					}
					break;
				default:
					$this->caldav->log(__METHOD__."(...) unknown xml tag '{$option['name']}': options[other]=".array2string($options['other']));
					break;
			}
		}
		// multiget --> fetch the url's
		if ($options['root']['name'] == 'addressbook-multiget')
		{
			$ids = array();
			foreach($options['other'] as $option)
			{
				if ($option['name'] == 'href')
				{
					$parts = explode('/',$option['data']);
					if (($id = urldecode(array_pop($parts))))
					{
						$ids[] = self::$path_extension ? basename($id,self::$path_extension) : $id;
					}
				}
			}
			if ($ids) $filters[self::$path_attr] = $ids;
			if ($this->debug) error_log(__METHOD__."(...) addressbook-multiget: ids=".implode(',',$ids));
		}
		elseif ($id)
		{
			$filters[self::$path_attr] = self::$path_extension ? basename($id,self::$path_extension) : $id;
		}
		//error_log(__METHOD__."() options[other]=".array2string($options['other'])." --> filters=".array2string($filters));
		return true;
	}

	/**
	 * Handle get request for an event
	 *
	 * @param array &$options
	 * @param int $id
	 * @param int $user =null account_id
	 * @return mixed boolean true on success, false on failure or string with http status (eg. '404 Not Found')
	 */
	function get(&$options,$id,$user=null)
	{
		unset($user);	// not used, but required by function signature

		if (!is_array($contact = $this->_common_get_put_delete('GET',$options,$id)))
		{
			return $contact;
		}
		$handler = self::_get_handler();
		$options['data'] = $contact['list_id'] ? $handler->getGroupVCard($contact) :
			$handler->getVCard($contact['id'],$this->charset,false);
		// e.g. Evolution does not understand 'text/vcard'
		$options['mimetype'] = 'text/x-vcard; charset='.$this->charset;
		header('Content-Encoding: identity');
		header('ETag: "'.$this->get_etag($contact).'"');
		return true;
	}

	/**
	 * Handle put request for a contact
	 *
	 * @param array &$options
	 * @param int $id
	 * @param int $user =null account_id of owner, default null
	 * @param string $prefix =null user prefix from path (eg. /ralf from /ralf/addressbook)
	 * @return mixed boolean true on success, false on failure or string with http status (eg. '404 Not Found')
	 */
	function put(&$options,$id,$user=null,$prefix=null)
	{
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).",$id,$user)");

		$oldContact = $this->_common_get_put_delete('PUT',$options,$id);
		if (!is_null($oldContact) && !is_array($oldContact))
		{
			if ($this->debug) error_log(__METHOD__."(,'$id', $user, '$prefix') returning ".array2string($oldContact));
			return $oldContact;
		}

		$handler = self::_get_handler();
		// Fix for Apple Addressbook
		$vCard = preg_replace('/item\d\.(ADR|TEL|EMAIL|URL)/', '\1',
			htmlspecialchars_decode($options['content']));
		$charset = null;
		if (!empty($options['content_type']))
		{
			$content_type = explode(';', $options['content_type']);
			if (count($content_type) > 1)
			{
				array_shift($content_type);
				foreach ($content_type as $attribute)
				{
					trim($attribute);
					list($key, $value) = explode('=', $attribute);
					switch (strtolower($key))
					{
						case 'charset':
							$charset = strtoupper(substr($value,1,-1));
					}
				}
			}
		}

		$contact = $handler->vcardtoegw($vCard, $charset);

		if (is_array($oldContact) || ($oldContact = $this->bo->read(array('contact_uid' => $contact['uid']))))
		{
			$contactId = $oldContact['id'];
			$retval = true;
		}
		else
		{
			// new entry
			$contactId = -1;
			$retval = '201 Created';
		}
		$is_group = $contact['##X-ADDRESSBOOKSERVER-KIND'] == 'group';
		if ($oldContact && $is_group !== isset($oldContact['list_id']))
		{
			throw new Api\Exception\AssertionFailed(__METHOD__."(,'$id',$user,'$prefix') can contact into group or visa-versa!");
		}

		if (!$is_group && is_array($contact['cat_id']))
		{
			$contact['cat_id'] = implode(',',$this->bo->find_or_add_categories($contact['cat_id'], $contactId));
		}
		elseif ($contactId > 0)
		{
			$contact['cat_id'] = $oldContact['cat_id'];
		}
		if (is_array($oldContact))
		{
			$contact['id'] = $oldContact['id'];
			// dont allow the client to overwrite certain values
			$contact['uid'] = $oldContact['uid'];
			$contact['owner'] = $oldContact['owner'];
			$contact['private'] = $oldContact['private'];
			$contact['carddav_name'] = $oldContact['carddav_name'];
			$contact['tid'] = $oldContact['tid'];
			$contact['creator'] = $oldContact['creator'];
			$contact['created'] = $oldContact['created'];
			$contact['account_id'] = $oldContact['account_id'];
		}
		else
		{
			$contact['carddav_name'] = $id;

			// only set owner, if user is explicitly specified in URL (check via prefix, NOT for /addressbook/) or sync-all-in-one!)
			if ($prefix && !in_array('O',$this->home_set_pref) && $user)
			{
				$contact['owner'] = $user;
			}
			// check if default addressbook is synced and not Api\Accounts, if not use (always synced) personal addressbook
			elseif(!$this->bo->default_addressbook || !in_array($this->bo->default_addressbook,$this->home_set_pref))
			{
				$contact['owner'] = $GLOBALS['egw_info']['user']['account_id'];
			}
			else
			{
				$contact['owner'] = $this->bo->default_addressbook;
				$contact['private'] = $this->bo->default_private;
			}
			// check if user has add rights for addressbook
			// done here again, as _common_get_put_delete knows nothing about default addressbooks...
			if (!($this->bo->grants[$contact['owner']] & Acl::ADD))
			{
				if ($this->debug) error_log(__METHOD__."(,'$id', $user, '$prefix') returning '403 Forbidden'");
				return '403 Forbidden';
			}
		}
		if ($this->http_if_match) $contact['etag'] = self::etag2value($this->http_if_match);

		if (!($save_ok = $is_group ? $this->save_group($contact, $oldContact) : $this->bo->save($contact)))
		{
			if ($this->debug) error_log(__METHOD__."(,$id) save(".array2string($contact).") failed, Ok=$save_ok");
			if ($save_ok === 0)
			{
				// honor Prefer: return=representation for 412 too (no need for client to explicitly reload)
				$this->check_return_representation($options, $id, $user);
				return '412 Precondition Failed';
			}
			return '403 Forbidden';	// happens when writing new entries in AB's without ADD rights
		}

		if (empty($contact['etag']) || empty($contact['cardav_name']))
		{
			if ($is_group)
			{
				if (($contact = $this->bo->read_list($save_ok)))
				{
					$contact = Api\Db::strip_array_keys($contact, 'list_');
				}
			}
			else
			{
				$contact = $this->bo->read($save_ok);
			}
			//error_log(__METHOD__."(, $id, '$user') read(_list)($save_ok) returned ".array2string($contact));
		}

		// send evtl. necessary respose headers: Location, etag, ...
		$this->put_response_headers($contact, $options['path'], $retval, self::$path_attr != 'id');

		if ($this->debug > 1) error_log(__METHOD__."(,'$id', $user, '$prefix') returning ".array2string($retval));
		return $retval;
	}

	/**
	 * Save distribition-list / group
	 *
	 * @param array $contact
	 * @param array|false $oldContact
	 * @return int|boolean $list_id or false on error
	 */
	function save_group(array &$contact, $oldContact=null)
	{
		$data = array('list_name' => $contact['n_fn']);
		if (!isset($contact['owner'])) $contact['owner'] = $GLOBALS['egw_info']['user']['account_id'];
		foreach(array('id','carddav_name','uid','owner') as $name)
		{
			$data['list_'.$name] = $contact[$name];
		}
		//error_log(__METHOD__.'('.array2string($contact).', '.array2string($oldContact).') data='.array2string($data));
		if (($list_id=$this->bo->add_list(array('list_'.self::$path_attr => $contact[self::$path_attr]),
			$contact['owner'], null, $data)))
		{
			// update members given in $contact['##X-ADDRESSBOOKSERVER-MEMBER']
			$new_members = $contact['##X-ADDRESSBOOKSERVER-MEMBER'];
			if ($new_members[1] == ':' && ($n = unserialize($new_members)))
			{
				$new_members = $n['values'];
			}
			else
			{
				$new_members = array($new_members);
			}
			foreach($new_members as &$uid)
			{
				$uid = substr($uid,9);	// cut off "urn:uuid:" prefix
			}
			if ($oldContact)
			{
				$to_add = array_diff($new_members,$oldContact['members']);
				$to_delete = array_diff($oldContact['members'],$new_members);
			}
			else
			{
				$to_add = $new_members;
			}
			//error_log('to_add='.array2string($to_add).', to_delete='.array2string($to_delete));
			if ($to_add || $to_delete)
			{
				$to_add_ids = $to_delete_ids = array();
				$filter = array('uid' => $to_delete ? array_merge($to_add, $to_delete) : $to_add);
				if (($contacts =& $this->bo->search(array(), array('id', 'uid'),'','','',False,'AND',false,$filter)))
				{
					foreach($contacts as $c)
					{
						if ($to_delete && in_array($c['uid'], $to_delete))
						{
							$to_delete_ids[] = $c['id'];
						}
						else
						{
							$to_add_ids[] = $c['id'];
						}
					}
				}
				//error_log('to_add_ids='.array2string($to_add_ids).', to_delete_ids='.array2string($to_delete_ids));
				if ($to_add_ids) $this->bo->add2list($to_add_ids, $list_id, array());
				if ($to_delete_ids) $this->bo->remove_from_list($to_delete_ids, $list_id);
			}
			// reread as update of list-members updates etag and modified
	 		$contact = $this->bo->read_list($list_id);
		}
		if ($this->debug > 1) error_log(__METHOD__.'('.array2string($contact).', '.array2string($oldContact).') on return contact='.array2string($data).' returning '.array2string($list_id));
 		return $list_id;
	}

	/**
	 * Query ctag for addressbook
	 *
	 * @param string $path
	 * @param int $user
	 * @return string
	 */
	public function getctag($path,$user)
	{
		static $ctags = array();	// a little per request caching, in case ctag and sync-token is both requested
		if (isset($ctags[$path])) return $ctags[$path];

		$user_in = $user;
		// not showing addressbook of a single user?
		if (is_null($user) || $user === '' || $path == '/addressbook/') $user = null;

		// If "Sync selected addressbooks into one" is set --> ctag need to take selected AB's into account too
		if ($user && $user == $GLOBALS['egw_info']['user']['account_id'] && in_array('O',$this->home_set_pref))
		{
			$user = array_merge((array)$user,array_keys($this->get_shared(true)));	// true: ignore all-in-one pref

			// include accounts ctag, if accounts stored different from contacts (eg.in LDAP or ADS)
			if ($this->bo->so_accounts && in_array('0', $user))
			{
				$accounts_ctag = $this->bo->get_ctag('0');
			}
		}
		$ctag = $this->bo->get_ctag($user);

		// include lists-ctag, if enabled
		if (in_array('D',$this->home_set_pref))
		{
			$lists_ctag = $this->bo->lists_ctag($user);
		}
		//error_log(__METHOD__."('$path', ".array2string($user_in).") --> user=".array2string($user)." --> ctag=$ctag=".date('Y-m-d H:i:s',$ctag).", lists_ctag=".($lists_ctag ? $lists_ctag.'='.date('Y-m-d H:i:s',$lists_ctag) : '').' returning '.max($ctag,$lists_ctag));
		unset($user_in);
		return $ctags[$path] = max($ctag, $accounts_ctag, $lists_ctag);
	}

	/**
	 * Add extra properties for addressbook collections
	 *
	 * Example for supported-report-set syntax from Apples Calendarserver:
	 * <D:supported-report-set>
	 *    <supported-report>
	 *       <report>
	 *          <addressbook-query xmlns='urn:ietf:params:xml:ns:carddav'/>
	 *       </report>
	 *    </supported-report>
	 *    <supported-report>
	 *       <report>
	 *          <addressbook-multiget xmlns='urn:ietf:params:xml:ns:carddav'/>
	 *       </report>
	 *    </supported-report>
	 * </D:supported-report-set>
	 * @link http://www.mail-archive.com/calendarserver-users@lists.macosforge.org/msg01156.html
	 *
	 * @param array $props =array() regular props by the Api\CalDAV handler
	 * @param string $displayname
	 * @param string $base_uri =null base url of handler
	 * @param int $user =null account_id of owner of collection
	 * @return array
	 */
	public function extra_properties(array $props, $displayname, $base_uri=null, $user=null)
	{
		unset($displayname, $base_uri, $user);	// not used, but required by function signature

		if (!isset($props['addressbook-description']))
		{
			// default addressbook description: can be overwritten via PROPPATCH, in which case it's already set
			$props['addressbook-description'] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'addressbook-description',$props['displayname']);
		}
		// setting an max image size, so iOS scales the images before transmitting them
		// we currently scale down to width of 240px, which tests shown to be ~20k
		$props['max-image-size'] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'max-image-size',24*1024);

		// supported reports (required property for CardDAV)
		$props['supported-report-set'] = array(
			'addressbook-query' => Api\CalDAV::mkprop('supported-report',array(
				Api\CalDAV::mkprop('report',array(
					Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'addressbook-query',''))))),
			'addressbook-multiget' => Api\CalDAV::mkprop('supported-report',array(
				Api\CalDAV::mkprop('report',array(
					Api\CalDAV::mkprop(Api\CalDAV::CARDDAV,'addressbook-multiget',''))))),
		);
		// only advertice rfc 6578 sync-collection report, if "delete-prevention" is switched on (deleted entries get marked deleted but not actualy deleted
		if ($GLOBALS['egw_info']['server']['history'])
		{
			$props['supported-report-set']['sync-collection'] = Api\CalDAV::mkprop('supported-report',array(
				Api\CalDAV::mkprop('report',array(
					Api\CalDAV::mkprop('sync-collection','')))));
		}
		return $props;
	}

	/**
	 * Get the handler and set the supported fields
	 *
	 * @return addressbook_vcal
	 */
	private function _get_handler()
	{
		$handler = new addressbook_vcal('addressbook','text/vcard');
		$supportedFields = $handler->supportedFields;
		// Apple iOS or OS X addressbook
		if ($this->agent == 'cfnetwork' || $this->agent == 'dataaccess')
		{
			$databaseFields = $handler->databaseFields;
			// use just CELL and IPHONE, CELL;WORK and CELL;HOME are NOT understood
			//'TEL;CELL;WORK'		=> array('tel_cell'),
			//'TEL;CELL;HOME'		=> array('tel_cell_private'),
			$supportedFields['TEL;CELL'] = array('tel_cell');
			unset($supportedFields['TEL;CELL;WORK']);
			$supportedFields['TEL;IPHONE'] = array('tel_cell_private');
			unset($supportedFields['TEL;CELL;HOME']);
			$databaseFields['X-ABSHOWAS'] = $supportedFields['X-ABSHOWAS'] = array('fileas_type');	// Horde vCard class uses uppercase prop-names!

			// Apple Addressbook pre Lion (OS X 10.7) messes up CLASS and CATEGORIES (Lion cant set them but leaves them alone)
			$matches = null;
			if (preg_match('|CFNetwork/([0-9]+)|i', $_SERVER['HTTP_USER_AGENT'],$matches) && $matches[1] < 520 ||
				// iOS 5.1.1 does not display CLASS or CATEGORY, but wrongly escapes multiple, comma-separated categories
				// and appends CLASS: PUBLIC to an empty NOTE: field --> leaving them out for iOS
				$this->agent == 'dataaccess')
			{
				unset($supportedFields['CLASS']);
				unset($databaseFields['CLASS']);
				unset($supportedFields['CATEGORIES']);
				unset($databaseFields['CATEGORIES']);
			}
			if (preg_match('|CFNetwork/([0-9]+)|i', $_SERVER['HTTP_USER_AGENT'],$matches) && $matches[1] < 520)
			{
				// gd cant parse or resize images stored from snow leopard addressbook: gd-jpeg:
				// - JPEG library reports unrecoverable error
				// - Passed data is not in 'JPEG' format
				// - Couldn't create GD Image Stream out of Data
				// FF (10), Safari (5.1.3) and Chrome (17) cant display it either --> ignore images
				unset($supportedFields['PHOTO']);
				unset($databaseFields['PHOTO']);
			}
			$handler->setDatabaseFields($databaseFields);
		}
		$handler->setSupportedFields('GroupDAV',$this->agent,$supportedFields);
		return $handler;
	}

	/**
	 * Handle delete request for an event
	 *
	 * @param array &$options
	 * @param int $id
	 * @return mixed boolean true on success, false on failure or string with http status (eg. '404 Not Found')
	 */
	function delete(&$options,$id)
	{
		if (!is_array($contact = $this->_common_get_put_delete('DELETE',$options,$id)))
		{
			return $contact;
		}
		if (isset($contact['list_id']))
		{
			$ok = $this->bo->delete_list($contact['list_id']) !== false;
		}
		elseif (($ok = $this->bo->delete($contact['id'],self::etag2value($this->http_if_match))) === 0)
		{
			return '412 Precondition Failed';
		}
		return $ok;
	}

	/**
	 * Read a contact
	 *
	 * We have to make sure to not return or even consider in read deleted contacts, as the might have
	 * the same UID and/or carddav_name as not deleted contacts and would block access to valid entries
	 *
	 * @param string|int $id
	 * @param string $path =null
	 * @return array|boolean array with entry, false if no read rights, null if $id does not exist
	 */
	function read($id, $path=null)
	{
		static $non_deleted_tids=null;
		if (is_null($non_deleted_tids))
		{
			$tids = $this->bo->content_types;
			unset($tids[Api\Contacts::DELETED_TYPE]);
			$non_deleted_tids = array_keys($tids);
		}
		$contact = $this->bo->read(array(self::$path_attr => $id, 'tid' => $non_deleted_tids));

		// if contact not found and accounts stored NOT like contacts, try reading it without path-extension as id
		if (is_null($contact) && $this->bo->so_accounts && ($c = $this->bo->read($test=basename($id, '.vcf'))))
		{
			$contact = $c;
		}

		// see if we have a distribution-list / group with that id
		// bo->read_list(..., true) limits returned uid to same owner's addressbook, as iOS and OS X addressbooks
		// only understands/shows that and if return more, save_lists would delete the others ones on update!
		$limit_in_ab = true;
		list(,$account_lid,$app) = explode('/',$path);	// eg. /<username>/addressbook/<id>
		// /<username>/addressbook/ with home_set_prefs containing 'O'=all-in-one contains selected ab's
		if($account_lid == $GLOBALS['egw_info']['user']['account_lid'] && $app == 'addressbook' && in_array('O',$this->home_set_pref))
		{
			$limit_in_ab = array_keys($this->get_shared(true));
			$limit_in_ab[] = $GLOBALS['egw_info']['user']['account_id'];
		}
		/* we are currently not syncing distribution-lists/groups to /addressbook/ as
		 * Apple clients use that only as directory gateway
		elseif ($account_lid == 'addressbook')	// /addressbook/ contains all readably contacts
		{
			$limit_in_ab = array_keys($this->bo->grants);
		}*/
		if (!$contact && ($contact = $this->bo->read_lists(array('list_'.self::$path_attr => $id),'contact_uid',$limit_in_ab)))
		{
			$contact = array_shift($contact);
			$contact['n_fn'] = $contact['n_family'] = $contact['list_name'];
			foreach(array('owner','id','carddav_name','modified','modifier','created','creator','etag','uid') as $name)
			{
				$contact[$name] = $contact['list_'.$name];
			}
			// if NOT limited to containing AB ($limit_in_ab === true), add that limit to etag
			if ($limit_in_ab !== true)
			{
				$contact['etag'] .= ':'.implode('-',$limit_in_ab);
			}
		}
		elseif($contact === array())	// not found from read_lists()
		{
			$contact = null;
		}

		if ($contact && $contact['tid'] == Api\Contacts::DELETED_TYPE)
		{
			$contact = null;	// handle deleted events, as not existing (404 Not Found)
		}
		if ($this->debug > 1) error_log(__METHOD__."('$id') returning ".array2string($contact));
		return $contact;
	}

	/**
	 * Check if user has the neccessary rights on a contact
	 *
	 * @param int $acl Acl::READ, Acl::EDIT or Acl::DELETE
	 * @param array|int $contact contact-array or id
	 * @return boolean null if entry does not exist, false if no access, true if access permitted
	 */
	function check_access($acl,$contact)
	{
		return $this->bo->check_perms($acl, $contact, true);	// true = deny to delete accounts
	}

	/**
	 * Get grants of current user and app
	 *
	 * Reimplemented to account for static LDAP ACL and accounts (owner=0)
	 *
	 * @return array user-id => EGW_ACL_ADD|EGW_ACL_READ|EGW_ACL_EDIT|EGW_ACL_DELETE pairs
	 */
	public function get_grants()
	{
		$grants = $this->bo->get_grants($this->bo->user);

		// remove add and delete grants for accounts (for admins too)
		// as accounts can not be created as contacts, they eg. need further data
		// and admins might not recognice they delete an account incl. its data
		if (isset($grants[0])) $grants[0] &= ~(EGW_ACL_ADD|EGW_ACL_DELETE);

		return $grants;
	}

	/**
	 * Return calendars/addressbooks shared from other users with the current one
	 *
	 * @param boolean $ignore_all_in_one =false if true, return selected addressbooks and not array() for all-in-one
	 * @return array account_id => account_lid pairs
	 */
	function get_shared($ignore_all_in_one=false)
	{
		$shared = array();

		// if "Sync all selected addressbook into one" is set --> no (additional) shared addressbooks
		if (!$ignore_all_in_one && in_array('O',$this->home_set_pref)) return array();

		// replace symbolic id's with real nummeric id's
		foreach(array(
			'G' => $GLOBALS['egw_info']['user']['account_primary_group'],
			'U' => '0',
		) as $sym => $id)
		{
			if (($key = array_search($sym, $this->home_set_pref)) !== false)
			{
				$this->home_set_pref[$key] = $id;
			}
		}
		foreach(array_keys($this->bo->get_addressbooks(Acl::READ)) as $id)
		{
			if (($id || $GLOBALS['egw_info']['user']['preferences']['addressbook']['hide_accounts'] !== '1') &&
				$GLOBALS['egw_info']['user']['account_id'] != $id &&	// no current user and no accounts, if disabled in ab prefs
				(in_array('A',$this->home_set_pref) || in_array((string)$id,$this->home_set_pref)) &&
				is_numeric($id) && ($owner = $id ? $this->accounts->id2name($id) : 'accounts'))
			{
				$shared[$id] = 'addressbook-'.$owner;
			}
		}
		return $shared;
	}

	/**
	 * Hook to add properties to CardDAV root
	 *
	 * OS X 10.11.4 addressbook does a propfind for "addressbook-home-set" and "directory-gateway"
	 * in the root and does not continue without it.
	 *
	 * @param array $data
	 */
	public static function groupdav_root_props(array $data)
	{
		$data['props']['addressbook-home-set'] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV, 'addressbook-home-set', array(
			Api\CalDAV::mkprop('href',$data['caldav']->base_uri.'/'.$GLOBALS['egw_info']['user']['account_lid'].'/')));

		$data['props']['principal-address'] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV, 'principal-address',
				$GLOBALS['egw_info']['user']['preferences']['addressbook']['hide_accounts'] === '1' ? '' : array(
				Api\CalDAV::mkprop('href',$data['caldav']->base_uri.'/addressbook-accounts/'.$GLOBALS['egw_info']['user']['person_id'].'.vcf')));

		$data['props']['directory-gateway'] = Api\CalDAV::mkprop(Api\CalDAV::CARDDAV, 'directory-gateway', array(
			Api\CalDAV::mkprop('href',$data['caldav']->base_uri.'/addressbook/')));
	}

	/**
	 * Return appliction specific settings
	 *
	 * @param array $hook_data values for keys 'location', 'type' and 'account_id'
	 * @return array of array with settings
	 */
	static function get_settings($hook_data)
	{
		$addressbooks = array(
			'A'	=> lang('All'),
			'G'	=> lang('Primary Group'),
			'U' => lang('Accounts'),
			'O' => lang('Sync all selected into one'),
			'D' => lang('Distribution lists as groups')
		);
		if (!isset($hook_data['setup']) && in_array($hook_data['type'], array('user', 'group')))
		{
			$user = $hook_data['account_id'];
			$addressbook_bo = new Api\Contacts();
			$addressbooks += $addressbook_bo->get_addressbooks(Acl::READ, null, $user);
			if ($user > 0)  unset($addressbooks[$user]);	// allways synced
			unset($addressbooks[$user.'p']);// ignore (optional) private addressbook for now
		}

		// allow to force no other addressbooks
		if ($hook_data['type'] === 'forced')
		{
			$addressbooks['N'] = lang('None');
		}

		// rewriting owner=0 to 'U', as 0 get's always selected by prefs
		// not removing it for default or forced prefs based on current users pref
		if (!isset($addressbooks[0]) && (in_array($hook_data['type'], array('user', 'group')) ||
			$GLOBALS['egw_info']['user']['preferences']['addressbook']['hide_accounts'] === '1'))
		{
			unset($addressbooks['U']);
		}
		else
		{
			unset($addressbooks[0]);
		}

		$settings = array();
		$settings['addressbook-home-set'] = array(
			'type'   => 'multiselect',
			'label'  => 'Addressbooks to sync in addition to personal addressbook',
			'name'   => 'addressbook-home-set',
			'help'   => lang('Only supported by a few fully conformant clients (eg. from Apple). If you have to enter a URL, it will most likely not be supported!').
				'<br/>'.lang('They will be sub-folders in users home (%1 attribute).','CardDAV "addressbook-home-set"').
				'<br/>'.lang('Select "%1", if your client does not support multiple addressbooks.',lang('Sync all selected into one')).
				'<br/>'.lang('Select "%1", if your client support groups, eg. OS X or iOS addressbook.',lang('Distribution lists as groups')),
			'values' => $addressbooks,
			'xmlrpc' => True,
			'admin'  => False,
		);
		return $settings;
	}
}
