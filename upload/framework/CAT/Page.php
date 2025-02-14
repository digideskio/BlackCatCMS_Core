<?php

/**
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 3 of the License, or (at
 *   your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful, but
 *   WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 *   General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, see <http://www.gnu.org/licenses/>.
 *
 *   @author          Black Cat Development
 *   @copyright       2013, Black Cat Development
 *   @link            http://blackcat-cms.org
 *   @license         http://www.gnu.org/licenses/gpl.html
 *   @category        CAT_Core
 *   @package         CAT_Core
 *
 */

if (!class_exists('CAT_Object', false))
{
    @include dirname(__FILE__) . '/Object.php';
}

if (!class_exists('CAT_Page', false))
{
    class CAT_Page extends CAT_Object
    {

        protected      $_config         = array( 'loglevel' => 8 );

        // current page
        private        $_page_id        = NULL;
        // active blocks
        private        $sections        = array();
        // helper handle
        private static $helper          = NULL;
        // singleton, but one instance per page_id!
        private static $instances       = array();

        /**
         * get instance for page with ID $page_id
         *
         * @access public
         * @param  integer $page_id
         * @return object
         **/
        public static function getInstance( $page_id )
        {
            if (!is_numeric($page_id))
                self::printFatalError('Invalid page ID!');
            if (!self::$helper)
                self::$helper = CAT_Helper_Page::getInstance();
            if ($page_id==-1 || !isset(self::$instances[$page_id]))
            {
                if ( $page_id == -1 )
                {
                    $page_id = self::$helper->selectPage();
                }
                self::$instances[$page_id] = new self($page_id);
                self::init($page_id);
            }
            return self::$instances[$page_id];
        }   // end function getInstance()

        /**
         * initialize current page
         **/
        final private static function init($page_id)
        {
            self::$instances[$page_id]->_page_id = $page_id;
            $prop = self::$instances[$page_id]->getProperties();
            foreach ( $prop as $key => $value )
            {
                if(!$value) continue;
                if(CAT_Registry::exists(strtoupper($key))) continue;
                if(is_array($value)) continue;
                CAT_Registry::register(strtoupper($key),$value,true);
            }
    		// Work-out if any possible in-line search boxes should be shown
    		if(SEARCH == 'public') {
    			CAT_Registry::register('SHOW_SEARCH', true,true);
    		} elseif(SEARCH == 'private' AND VISIBILITY == 'private') {
    			CAT_Registry::register('SHOW_SEARCH', true,true);
    		} elseif(SEARCH == 'private' AND CAT_User::getInstance()->is_authenticated() == true) {
    			CAT_Registry::register('SHOW_SEARCH', true,true);
    		} elseif(SEARCH == 'registered' AND CAT_User::getInstance()->is_authenticated() == true) {
    			CAT_Registry::register('SHOW_SEARCH', true,true);
    		} else {
    			CAT_Registry::register('SHOW_SEARCH', false,true);
    		}
    		// Work-out if menu should be shown
    		if(!defined('SHOW_MENU')) {
    			CAT_Registry::register('SHOW_MENU', true,true);
    		}
    		// Work-out if login menu constants should be set
    		if(FRONTEND_LOGIN) {
                $constants = array(
    		        'LOGIN_URL'       => CAT_URL.'/account/login.php',
    		        'LOGOUT_URL'      => CAT_URL.'/account/logout.php',
    		        'FORGOT_URL'      => CAT_URL.'/account/forgot.php',
    		        'PREFERENCES_URL' => CAT_URL.'/account/preferences.php',
    		        'SIGNUP_URL'      => CAT_URL.'/account/signup.php',
                );
    			// Set login menu constants
                CAT_Registry::register($constants,NULL,true);
                global $parser;
                $parser->setGlobals( array(
                    'username_fieldname' => CAT_Helper_Validate::getInstance()->createFieldname('username_'),
                    'password_fieldname' => CAT_Helper_Validate::getInstance()->createFieldname('password_'),
                ));
                $parser->setGlobals($constants);
    		}
        }   // end function init()

        /**
         * shows the current page
         *
         * @access public
         * @return void
         **/
        public function show()
        {

            // ----- keep old modules happy -----
            global $wb, $admin, $database, $page_id, $section_id;
            $admin =& $wb;
            if ( $page_id == '' )
                $page_id = $this->_page_id;
            // ----- keep old modules happy -----

            $this->log()->LogDebug(sprintf('showing page with ID [%s]',$page_id));

            // template engine
            global $parser;

            // page of type menu_link
            if(CAT_Sections::isMenuLink($this->_page_id))
            {
                $this->showMenuLink();
            }
            else
            {
                $do_filter = false;
                // use output filter (if any)
                if(file_exists(sanitize_path(CAT_PATH.'/modules/blackcatFilter/filter.php')))
                {
                    include_once sanitize_path(CAT_PATH.'/modules/blackcatFilter/filter.php');
                    if(function_exists('executeFilters'))
                    {
                        $this->log()->LogDebug('enabling output filters');
                        $do_filter = true;
                }
                }
                $this->setTemplate();

                // including the template; it may calls different functions
                // like page_content() etc.
                ob_start();
                    require CAT_TEMPLATE_DIR.'/index.php';
                    $output = ob_get_contents();
                ob_clean();

                // droplets
                CAT_Helper_Droplet::process($output);

                // output filtering
                if ( $do_filter )
                {
                    $this->log()->LogDebug('executing output filters');
                    executeFilters($output);
                }

                // use HTMLPurifier to clean up the output
                if( defined('ENABLE_HTMLPURIFIER') && true === ENABLE_HTMLPURIFIER )
                {
                    $this->log()->LogDebug('executing HTML Purifier');
                    $output = CAT_Helper_Protect::purify($output);
                }

                $this->log()->LogDebug('print output');

                echo $output;
            }
        }   // end function show()

        /**
         * returns page description
         **/
        public function getDescription()
        {
            $desc = self::$helper->properties($this->_page_id,'description');
            if ( !$desc ) $desc = CAT_Registry::get('WEBSITE_DESCRIPTION');
            return $desc;
        }   // end function getDescription()

        /**
         * returns page keywords
         **/
        public function getKeywords()
        {
            $kw = self::$helper->properties($this->_page_id,'keywords');
            if ( !$kw ) $kw = CAT_Registry::get('WEBSITE_KEYWORDS');
            return $kw;
        }   // end function getKeywords()

        /**
         * creates a menu of linked pages in other languages
         **/
        public function getLanguageMenu()
        {
            global $parser, $page_id;
            if (defined('PAGE_LANGUAGES') && PAGE_LANGUAGES)
            {
                $items = CAT_Helper_Page::getInstance()->getLinkedByLanguage($page_id);
            }
            if( isset($items) && count($items) )
            {
                // initialize template search path
                $parser->setPath(CAT_PATH.'/templates/'.TEMPLATE.'/templates');
                $parser->setFallbackPath(CAT_THEME_PATH.'/templates');
                if($parser->hasTemplate('languages'))
                {
                    $parser->output('languages', array('items'=>$items));
                }
            }
        }   // end function getLanguageMenu()

        /**
         * get page sections for given block
         *
         * @access public
         * @param  integer $block
         * @return void (direct print to STDOUT)
         **/
        public function getPageContent($block = 1)
        {

            // keep old modules happy
            global $wb, $admin, $database, $page_id, $section_id, $parser;
            // old style language files
            global $TEXT, $HEADING, $MESSAGE;

            $admin =& $wb;
            if ( $page_id == '' )
                $page_id = $this->_page_id;

            // check if user is allowed to see this page
            if(
                   !self::$helper->isVisible($this->_page_id)
                && !CAT_Users::is_root()
                && (!self::$helper->isMaintenance() || CAT_Registry::get('MAINTENANCE_PAGE') != $this->_page_id)
            ) {
                if(self::$helper->isDeleted($this->_page_id))
                {
                    return self::print404();
                }
                else
                {
                self::$helper->printFatalError('You are not allowed to view this page!');
                }
            }
            // check if page has active sections
            if(!self::$helper->isActive($this->_page_id))
                return self::$helper->lang()->translate('The page does not have any content!');

            // get the page content; if constant PAGE_CONTENT is set, it contains
            // the name of a file to be included
            if (!defined('PAGE_CONTENT') or $block != 1)
            {
                // get active sections
                $sections = CAT_Sections::getActiveSections($this->_page_id, $block);
                if(is_array($sections) && count($sections))
                {
                    global $parser, $section_id;
                    foreach ($sections as $section)
                    {
                        self::$helper->log()->logDebug('sections for this block', $sections);
                        $section_id = $section['section_id'];
                        $module     = $section['module'];
                        // make a anchor for every section.
                        if (defined('SEC_ANCHOR') && SEC_ANCHOR != '')
                        {
                            echo '<a class="section_anchor" id="' . SEC_ANCHOR . $section_id . '"></a>';
                        }
                        // check if module exists - feature: write in errorlog
                        if (file_exists(CAT_PATH . '/modules/' . $module . '/view.php'))
                        {
                            // load language file (if any)
                            $langfile = sanitize_path(CAT_PATH.'/modules/'.$module.'/languages/'.LANGUAGE.'.php');
                            if ( file_exists($langfile) )
                            {
                                if ( ! $this->lang()->checkFile($langfile, 'LANG', true ))
                                // old fashioned language file
                                require $langfile;
                            else
                                // modern language file
                            $this->lang()->addFile(LANGUAGE . '.php', sanitize_path(CAT_PATH . '/modules/' . $module . '/languages'));
                            }
                            // set template path
                            if (file_exists(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates')))
                                $parser->setPath(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates'));
                            if (file_exists(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates/default')))
                                $parser->setPath(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates/default'));
                            if (file_exists(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates/' . DEFAULT_TEMPLATE)))
                            {
                                $parser->setFallbackPath(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates/default'));
                                $parser->setPath(sanitize_path(CAT_PATH . '/modules/' . $module . '/templates/' . DEFAULT_TEMPLATE));
                            }
                            // fetch original content
                            ob_start();
                                require(CAT_PATH . '/modules/' . $module . '/view.php');
                                $content = ob_get_clean();
                            echo $content;
                        }
                        else
                        {
                            continue;
                        }
                    }
                }
            }
            else
            {
                require(PAGE_CONTENT);
            }
            if (!CAT_Registry::exists('CAT_PAGE_CONTENT_DONE'))
            CAT_Registry::register('CAT_PAGE_CONTENT_DONE',true,true);
        }

        /**
         * returns the properties of the current page (contents of private
         * $_page hash)
         *
         * @access public
         * @return array
         **/
        public function getProperties()
        {
            return self::$helper->properties($this->_page_id);
        }   // end function getProperties()

        /**
         *
         * @access public
         * @return
         **/
        public function getSections() {
            if(!count($this->sections))
            {
                $sec = self::$helper->db()->query(sprintf(
                    'SELECT * FROM `%ssections` WHERE `page_id` = %d ORDER BY position ASC',
                    CAT_TABLE_PREFIX, $this->_page_id
                ));
                if ( $sec->numRows() > 0 )
                {
                	while ( false !== ( $section = $sec->fetchRow( MYSQL_ASSOC ) ) )
                	{
                		$this->sections[] = $section;
                    }
                }
            }
            return $this->sections;
        }   // end function getSections()
        

        /**
         * Figure out which template to use
         *
         * @access public
         * @return void   sets globals
         **/
        public function setTemplate()
        {
    		if(!defined('TEMPLATE'))
            {
                $prop = $this->getProperties();
                // page has it's own template
    			if(isset($prop['template']) && $prop['template'] != '') {
    				if(file_exists(CAT_PATH.'/templates/'.$prop['template'].'/index.php')) {
    					CAT_Registry::register('TEMPLATE', $prop['template'], true);
    				} else {
    					CAT_Registry::register('TEMPLATE', DEFAULT_TEMPLATE, true);
    				}
                // use global default
    			} else {
    				CAT_Registry::register('TEMPLATE', DEFAULT_TEMPLATE, true);
    			}
    		}
            $dir = '/templates/'.TEMPLATE;
    		// Set the template dir (which is, in fact, the URL, but for backward
            // compatibility, we have to keep this irritating name)
    		CAT_Registry::register('TEMPLATE_DIR', CAT_URL.$dir, true);
            // This is the REAL dir
            CAT_Registry::register('CAT_TEMPLATE_DIR', CAT_PATH.$dir, true);
        }   // end function setTemplate()


        /**
         *
         * @access public
         * @return
         **/
        public static function print404()
        {
            if ( CAT_Registry::defined('ERR_PAGE') && CAT_Registry::get('ERR_PAGE') != '' )
            {
                header('Location: '.self::$helper->getLink(CAT_Registry::get('ERR_PAGE')));
            }
            else
            {
                header($_SERVER['SERVER_PROTOCOL'].' 404 Not found');
            }
        }   // end function print404()
        

// *****************************************************************************
//
// *****************************************************************************

        private function showMenuLink()
        {
       	    // get target_page_id
            $tpid = self::$helper->db()->query(sprintf(
                  'SELECT * FROM `%smod_menu_link` '
                . 'WHERE `page_id` = %d',
                CAT_TABLE_PREFIX,
                $this->_page_id
            ));
        	if($tpid->numRows() == 1)
        	{
        		$res = $tpid->fetchRow();
        		$target_page_id = $res['target_page_id'];
        		$redirect_type = $res['redirect_type'];
        		$anchor = ($res['anchor'] != '0' ? '#'.(string)$res['anchor'] : '');
        		$extern = $res['extern'];
        		// set redirect-type
        		if($redirect_type == 301) {
        			@header('HTTP/1.1 301 Moved Permanently', TRUE, 301);
        		}
        		if($target_page_id == -1)
        		{
        			if($extern != '')
        			{
        				$target_url = $extern.$anchor;
        				header('Location: '.$target_url);
        				exit;
        			}
        		}
        		else
        		{
        			// get link of target-page
                    $target_page = $target_page_link = self::$helper->properties($target_page_id);
        			$target_page_link = ( isset($target_page['link']) )
                                      ? $target_page['link']
                                      : NULL;
        			if($target_page_link != NULL)
        			{
        				$target_url = CAT_URL.PAGES_DIRECTORY.$target_page_link.PAGE_EXTENSION.$anchor;
        				header('Location: '.$target_url);
        				exit;
        			}
        		}
        	}
        }   // end function showMenuLink()


    } // end class

}
