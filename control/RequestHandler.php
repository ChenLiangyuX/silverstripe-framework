<?php

/**
 * This class is the base class of any SilverStripe object that can be used to handle HTTP requests.
 * 
 * Any RequestHandler object can be made responsible for handling its own segment of the URL namespace.
 * The {@link Director} begins the URL parsing process; it will parse the beginning of the URL to identify which
 * controller is being used.  It will then call {@link handleRequest()} on that Controller, passing it the parameters that it
 * parsed from the URL, and the {@link SS_HTTPRequest} that contains the remainder of the URL to be parsed.
 *
 * You can use ?debug_request=1 to view information about the different components and rule matches for a specific URL.
 *
 * In SilverStripe, URL parsing is distributed throughout the object graph.  For example, suppose that we have a search form
 * that contains a {@link TreeMultiSelectField} named "Groups".  We want to use ajax to load segments of this tree as they are needed
 * rather than downloading the tree right at the beginning.  We could use this URL to get the tree segment that appears underneath
 * Group #36: "admin/crm/SearchForm/field/Groups/treesegment/36"
 *  - Director will determine that admin/crm is controlled by a new ModelAdmin object, and pass control to that.
 *    Matching Director Rule: "admin/crm" => "ModelAdmin" (defined in mysite/_config.php)
 *  - ModelAdmin will determine that SearchForm is controlled by a Form object returned by $this->SearchForm(), and pass control to that.
 *    Matching $url_handlers: "$Action" => "$Action" (defined in RequestHandler class)
 *  - Form will determine that field/Groups is controlled by the Groups field, a TreeMultiselectField, and pass control to that.
 *    Matching $url_handlers: 'field/$FieldName!' => 'handleField' (defined in Form class)
 *  - TreeMultiselectField will determine that treesegment/36 is handled by its treesegment() method.  This method will return an HTML fragment that is output to the screen.
 *    Matching $url_handlers: "$Action/$ID" => "handleItem" (defined in TreeMultiSelectField class)
 *
 * {@link RequestHandler::handleRequest()} is where this behaviour is implemented.
 * 
 * @package framework
 * @subpackage control
 */
class RequestHandler extends ViewableData {
	
	/**
	 * @var SS_HTTPRequest $request The request object that the controller was called with.
	 * Set in {@link handleRequest()}. Useful to generate the {}
	 */
	protected $request = null;
	
	/**
	 * The DataModel for this request
	 */
	protected $model = null;
	
	/**
	 * This variable records whether RequestHandler::__construct()
	 * was called or not. Useful for checking if subclasses have
	 * called parent::__construct()
	 *
	 * @var boolean
	 */
	protected $brokenOnConstruct = true;
	
	/**
	 * The default URL handling rules.  This specifies that the next component of the URL corresponds to a method to
	 * be called on this RequestHandlingData object.
	 *
	 * The keys of this array are parse rules.  See {@link SS_HTTPRequest::match()} for a description of the rules available.
	 * 
	 * The values of the array are the method to be called if the rule matches.  If this value starts with a '$', then the
	 * named parameter of the parsed URL wil be used to determine the method name.
	 */
	static $url_handlers = array(
		'$Action' => '$Action',
	);

	
	/**
	 * Define a list of action handling methods that are allowed to be called directly by URLs.
	 * The variable should be an array of action names. This sample shows the different values that it can contain:
	 *
	 * <code>
	 * array(
	 *		'someaction', // someaction can be accessed by anyone, any time
	 *		'otheraction' => true, // So can otheraction
	 *		'restrictedaction' => 'ADMIN', // restrictedaction can only be people with ADMIN privilege
	 *		'complexaction' '->canComplexAction' // complexaction can only be accessed if $this->canComplexAction() returns true
	 *	);
	 * </code>
	 * 
	 * Form getters count as URL actions as well, and should be included in allowed_actions.
	 * Form actions on the other handed (first argument to {@link FormAction()} shoudl NOT be included,
	 * these are handled separately through {@link Form->httpSubmission}. You can control access on form actions
	 * either by conditionally removing {@link FormAction} in the form construction,
	 * or by defining $allowed_actions in your {@link Form} class.
	 */
	static $allowed_actions = null;
	 
	public function __construct() {
		$this->brokenOnConstruct = false;

		$this->request = new RoutedRequest();

		// This will prevent bugs if setDataModel() isn't called.
		$this->model = DataModel::inst();
		
		parent::__construct();
	}
	
	/**
	 * Set the DataModel for this request.
	 */
	public function setDataModel($model) {
		$this->model = $model;
	}
	
	/**
	 * Handles URL requests.
	 *
	 *  - ViewableData::handleRequest() iterates through each rule in {@link self::$url_handlers}.
	 *  - If the rule matches, the named method will be called.
	 *  - If there is still more URL to be processed, then handleRequest() 
	 *    is called on the object that that method returns.
	 *
	 * Once all of the URL has been processed, the final result is returned.  
	 * However, if the final result is an array, this
	 * array is interpreted as being additional template data to customise the 
	 * 2nd to last result with, rather than an object
	 * in its own right.  This is most frequently used when a Controller's 
	 * action will return an array of data with which to
	 * customise the controller.
	 * 
	 * @param $request The {@link SS_HTTPRequest} object that is reponsible for distributing URL parsing
	 * @uses SS_HTTPRequest
	 * @uses SS_HTTPRequest->match()
	 * @return SS_HTTPResponse|RequestHandler|string|array
	 */
	function handleRequest(SS_HTTPRequest $request, DataModel $model) {
		$class = get_class($this);

		if($this->brokenOnConstruct) {
			user_error("parent::__construct() needs to be called on {$handlerClass}::__construct()", E_USER_WARNING);
		}
	
		$this->request = $request;
		$this->setDataModel($model);

		$router = new Router();
		$router->setRequest($request);

		// Traverse up the class hierarchy, checking each set of url handlers.
		while($class && $class != get_parent_class(__CLASS__)) {
			if(!$handlers = Config::inst()->get($class, 'url_handlers', Config::UNINHERITED)) {
				$class = get_parent_class($class);
				continue;
			}

			if($action = $router->route(null, $handlers)) {
				if(isset($_REQUEST['debug_request'])) {
					Debug::message(sprintf(
						'Request matched to "%s::%s", with params: %s',
						$class, $action, var_export($request->getLatestParams(), true)
					));
				}

				// Check for an action name that is referencing a parameter.
				if($action[0] == '$') {
					$action = $request->getLatestParam(substr($action, 1));
				}

				if(!$this->checkAccessAction($action)) {
					$this->httpError(403, sprintf(
						'Action "%s" is not allowed on class "%s"', $action, get_class($this)
					));
				}

				if(!$action) {
					$action = 'index';
				} elseif(!is_string($action)) {
					throw new \Exception('Non-string action name encountered');
				}

				if(!$this->hasMethod($action)) {
					$this->httpError(404, sprintf(
						'Action "%s" is not available on class "%s"', $action, get_class($this)
					));
				}

				try {
					$result = $this->$action($request);
				} catch(SS_HTTPResponse_Exception $ex) {
					$result = $ex->getResponse();
				}

				if($result instanceof SS_HTTPRequest && $result->isError()) {
					if(isset($_REQUEST['debug_request'])) {
						Debug::message('Rule resulted in HTTP error; breaking');
					}

					return $result;
				}

				if($result instanceof RequestHandler && $this !== $result) { // !$request->isEmptyPattern($rule)
					$result = $result->handleRequest($request, $model);

					if(is_array($result)) {
						$result = $this->customise($result);
					}

					return $result;
				}

				// If there's still more content on the URL which cannot be
				// handled then return an error.
				if(!$request->allParsed()) {
					return $this->httpError(404, sprintf(
						'Cannot handle sub-URLs of a %s object', get_class($this)
					));
				}

				return $result;
			}

			$class = get_parent_class($class);
		}

		// If no match was made then just return this object.
		return $this;
	}
	
	/**
	 * Get a unified array of allowed actions on this controller (if such data is available) from both the controller
	 * ancestry and any extensions.
	 *
	 * @return array|null
	 */
	public function allowedActions() {

		$actions = Config::inst()->get(get_class($this), 'allowed_actions');

		if($actions) {
			// convert all keys and values to lowercase to 
			// allow for easier comparison, unless it is a permission code
			$actions = array_change_key_case($actions, CASE_LOWER);
			
			foreach($actions as $key => $value) {
				if(is_numeric($key)) $actions[$key] = strtolower($value);
			}

			return $actions;
		}
	}
	
	/**
	 * Checks if this request handler has a specific action (even if the current user cannot access it).
	 *
	 * @param string $action
	 * @return bool
	 */
	public function hasAction($action) {
		if($action == 'index') return true;
		
		$action  = strtolower($action);
		$actions = $this->allowedActions();

		// Check if the action is defined in the allowed actions as either a
		// key or value. Note that if the action is numeric, then keys are not
		// searched for actions to prevent actual array keys being recognised
		// as actions.
		if(is_array($actions)) {
			$isKey   = !is_numeric($action) && array_key_exists($action, $actions);
			$isValue = in_array($action, $actions, true);
			$isWildcard = (in_array('*', $actions) && $this->checkAccessAction($action));
			if($isKey || $isValue || $isWildcard) return true;
		}

		if(!is_array($actions) || !$this->config()->get('allowed_actions', Config::UNINHERITED | Config::EXCLUDE_EXTRA_SOURCES)) {
			if($action != 'init' && $action != 'run' && method_exists($this, $action)) return true;
		}
		
		return false;
	}
	
	/**
	 * Check that the given action is allowed to be called from a URL.
	 * It will interrogate {@link self::$allowed_actions} to determine this.
	 */
	function checkAccessAction($action) {
		$actionOrigCasing = $action;
		$action            = strtolower($action);
		$allowedActions    = $this->allowedActions();

		if($allowedActions)  {
			// check for specific action rules first, and fall back to global rules defined by asterisk
			foreach(array($action,'*') as $actionOrAll) {
				// check if specific action is set
				if(isset($allowedActions[$actionOrAll])) {
					$test = $allowedActions[$actionOrAll];
					if($test === true || $test === 1 || $test === '1') {
						// Case 1: TRUE should always allow access
						return true;
					} elseif(substr($test, 0, 2) == '->') {
						// Case 2: Determined by custom method with "->" prefix
						return $this->{substr($test, 2)}();
					} else {
						// Case 3: Value is a permission code to check the current member against
						return Permission::check($test);
					}
					
				} elseif((($key = array_search($actionOrAll, $allowedActions, true)) !== false) && is_numeric($key)) {
					// Case 4: Allow numeric array notation (search for array value as action instead of key)
					return true;
				}
			}
		}
		
		// If we get here an the action is 'index', then it hasn't been specified, which means that
		// it should be allowed.
		if($action == 'index' || empty($action)) return true;
		
		if($allowedActions === null || !$this->config()->get('allowed_actions', Config::UNINHERITED | Config::EXCLUDE_EXTRA_SOURCES)) {
			// If no allowed_actions are provided, then we should only let through actions that aren't handled by magic methods
			// we test this by calling the unmagic method_exists. 
			if(method_exists($this, $action)) {
				// Disallow any methods which aren't defined on RequestHandler or subclasses
				// (e.g. ViewableData->getSecurityID())
				$r = new ReflectionClass(get_class($this));
				if($r->hasMethod($actionOrigCasing)) {
					$m = $r->getMethod($actionOrigCasing);
					return ($m && is_subclass_of($m->getDeclaringClass()->getName(), 'RequestHandler'));
				} else {
					throw new Exception("method_exists() true but ReflectionClass can't find method - PHP is b0kred");
				}
			} else if(!$this->hasMethod($action)){
				// Return true so that a template can handle this action
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Throws a HTTP error response encased in a {@link SS_HTTPResponse_Exception}, which is later caught in
	 * {@link RequestHandler::handleAction()} and returned to the user.
	 *
	 * @param int $errorCode
	 * @param string $errorMessage Plaintext error message
	 * @uses SS_HTTPResponse_Exception
	 */
	public function httpError($errorCode, $errorMessage = null) {
		$e = new SS_HTTPResponse_Exception($errorMessage, $errorCode);

		// Error responses should always be considered plaintext, for security reasons
		$e->getResponse()->addHeader('Content-Type', 'text/plain');

		throw $e;
	}

	/**
	 * @deprecated 3.0 Use SS_HTTPRequest->isAjax() instead (through Controller->getRequest())
	 */
	function isAjax() {
		Deprecation::notice('3.0', 'Use SS_HTTPRequest->isAjax() instead (through Controller->getRequest())');
		return $this->request->isAjax();
	}

	/**
	 * Returns the request object that this controller is handling.
	 *
	 * @return SS_HTTPRequest
	 */
	function getRequest() {
		return $this->request;
	}
	
	/**
	 * Typically the request is set through {@link handleAction()}
	 * or {@link handleRequest()}, but in some based we want to set it manually.
	 * 
	 * @param SS_HTTPRequest
	 */
	function setRequest($request) {
		$this->request = $request;
	}
}
