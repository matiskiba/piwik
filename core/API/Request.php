<?php
/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id$
 * 
 * @category Piwik
 * @package Piwik
 */

/**
 * An API request is the object used to make a call to the API and get the result.
 * The request has the format of a normal GET request, ie. parameter_1=X&parameter_2=Y
 * 
 * You can use this object from anywhere in piwik (inside plugins for example).
 * You can even call it outside of piwik  using the REST API over http
 * or in a php script on the same server as piwik, by including piwik/index.php
 * (see examples in the documentation http://piwik.org/docs/analytics-api)
 * 
 * Example: 
 * $request = new Piwik_API_Request('
 * 				method=UserSettings.getWideScreen
 * 				&idSite=1
 *  			&date=yesterday
 * 				&period=week
 *				&format=xml
 *				&filter_limit=5
 *				&filter_offset=0
 *	');
 *	$result = $request->process();
 *  echo $result;
 * 
 * @see http://piwik.org/docs/analytics-api
 * @package Piwik
 * @subpackage Piwik_API
 */
class Piwik_API_Request
{	
	protected $request = null;

	/**
	 * Returns the request array as string
	 *
	 * @param string|array  $request
	 * @return array|null
	 */
	static public function getRequestArrayFromString($request)
	{
		$defaultRequest = $_GET + $_POST;
		$requestArray = $defaultRequest;
		
		if(!is_null($request))
		{
            if(is_array($request)) 
            {
                $url = array();
                foreach ($request as $key => $value) 
                {
                    $url[] = $key . "=" . $value;
                }
                $request = implode("&", $url);
            }

			$request = trim($request);
			$request = str_replace(array("\n","\t"),'', $request);
			parse_str($request, $requestArray);
		
			$requestArray = $requestArray + $defaultRequest;
		}
		
		foreach($requestArray as &$element)
		{
			if(!is_array($element))
			{
				$element = trim($element);
			}
		}
		return $requestArray;
	}
	
	/**
	 * Constructs the request to the API, given the request url
	 *
	 * @param string  $request  GET request that defines the API call (must at least contain a "method" parameter)
	 *                          Example: method=UserSettings.getWideScreen&idSite=1&date=yesterday&period=week&format=xml
	 *                          If a request is not provided, then we use the $_GET and $_POST superglobal and fetch
	 *                          the values directly from the HTTP GET query.
	 */
	function __construct($request = null)
	{
		$this->request = self::getRequestArrayFromString($request);
	}

	/**
	 * Handles the request to the API.
	 * It first checks that the method called (parameter 'method') is available in the module (it means that the method exists and is public)
	 * It then reads the parameters from the request string and throws an exception if there are missing parameters.
	 * It then calls the API Proxy which will call the requested method.
	 *
	 * @throws Piwik_FrontController_PluginDeactivatedException
	 * @return mixed  The data resulting from the API call
	 */
	public function process()
	{
		// read the format requested for the output data
		$outputFormat = strtolower(Piwik_Common::getRequestVar('format', 'xml', 'string', $this->request));
		
		// is bulkapi enabled? if bulk=true appears in the request.
		$bulkapi = isset($this->request["bulk"]) && ($this->request["bulk"]=="true");
		
		// if bulkapi  
		if ( $bulkapi )
		{
		  // order of execution must be kept
		  // TODO: add support for otherwise
      $apiBulkKeepRequestOrder = true;            
		
		  // deduce how many requests exist: the maximum length of any array found in the request 
		  $apiCallCount = 1;
		  foreach ($this->request as $key=>$value)
		    if ( is_array($value) )
		      $apiCallCount = max($apiCallCount,count($value));

      // construct requests collection:		      
      //    If request includes multiple request elements (i.e. arrays): construct a
      //    new request element which is flat. As expected by the API handler.
      //    If certain array has less elements then the current request index, use its
      //    latest.
  		$requests = array();
      // gather all api modules               
  		$modules = array();
      for ( $apiCallIndex = 0 ; $apiCallIndex < $apiCallCount ; $apiCallIndex++ )
      {
        $request = array();
  		  foreach ($this->request as $key=>$value)
  		    if ( is_array($value) )
  		      $request[$key] = $value[min(count($value)-1,$apiCallIndex)];
  		    else
  		      $request[$key] = $value;
  		  $requests[] = $request;
  		  
  		  if ( isset($request["method"]) )
  		  {
    		  $moduleMethod = $request["method"];
    		  list($module, $method) = $this->extractModuleAndMethod($moduleMethod); 
    		  $modules[$module] = true;    		  
    		}
      }
            
      // construct grouping collection:
      //    the collection holds indexes of the requests that can be handled
      //    in a single call to the API function
      //
      //    to construct that collection we perform:
      //    1. call the analyzeBulkAPIFromRequests method of the API module, if
      //       exists, and retrieve its grouping decision
      //    2. split the grouping decision so that execution order is kept and 
      //       the same user credentials are the same for the whole execution
      //       group
      $grouping = array();
      foreach ( array_keys($modules) as $module )
      {
        // fail if plugin is not activated
  			if(!Piwik_PluginsManager::getInstance()->isPluginActivated($module))
  			{
  				throw new Piwik_FrontController_PluginDeactivatedException($module);
  			}    		  

        // construct module class from module name      
    		$moduleClass = "Piwik_" . $module . "_API";

        // retrieve grouping decision from API    		
    		$groups = Piwik_API_Proxy::getInstance()->analyzeBulkAPIFromRequests($moduleClass,&$requests);
        
        if ( $apiBulkKeepRequestOrder )
          // iterate over all groups in API's grouping decision            		
      		foreach ( $groups as $group )
      		{
      		  // init lastIndex,last token_auth and current split group
      		  $lastIndex = null;
      		  $lastTokenAuth = null;
      		  $splitGroup = array();
      		  // iterate over all indexes in group, assuming its sorted.
      		  foreach ( $group[0] as $idx )
      		    // if
              //    - the current index = the last index + 1, or no last index
              //  AND
              //    - the current token_auth = the last token_auth, or no last token_auth
              // then: update last index, last token_auth and split group 
      		    if ( ( ( $lastIndex === null ) || ( $idx == ( $lastIndex + 1 ) ) ) && ( ( $lastTokenAuth === null ) || ( $requests[$idx]["token_auth"] == $lastTokenAuth ) ) )
      		    {
      		      $lastIndex = $idx;
      		      $lastTokenAuth = $requests[$idx]["token_auth"];
      		      $splitGroup[] = $idx;
      		    }
      		    else
      		    // else: add the split group to the grouping collection
      		    {
      		      $grouping[] = array($splitGroup,$group[1],$group[2]);
      		      $lastIndex = null;
      		      $splitGroup = array();
              }
            
            // add last split group to the grouping collection  
      		  $grouping[] = array($splitGroup,$group[1],$group[2]);
          }
        else
          $grouping = array_merge($grouping,$groups);
      }

      // init variables before actual API request iteration:
      // * response variable
  		$response = new Piwik_API_ResponseBuilder($outputFormat, $this->request);
      // * data to return, should be null until end of iteration, unless an
      //   exception occured       
  		$toReturn = null;  		
  		// * array of returned values
  		$returnedValues = array();  		      
  		// * last token auth: prevent the need to reload the same auth multiple times
      $last_token_auth = null;      
      // * list of processed requests, by their indexes
      $processedRequestsIndexes = array();
      
      // iterate over the requests collection, by index      
      for ( $i = 0 ; $i < count($requests) ; $i++ )
      {
        // if the index has been processed, skip 
        if ( isset($processedRequestsIndexes[$i]) )
          continue;
          
        // iterate over the grouping collection
        foreach ( $grouping as $group )
        {          
          list($indexes,$moduleClass,$method) = $group;
          
          // if the group contains the current request index, lets process it
          // and the largest subset of the group possible        
          if ( in_array($i,$indexes) )
          {
            // sort the indexes collection
            sort($indexes);
            
            // construct collection of valid requests and mark each index in 
            // that collection as processed request
            $validRequests = array();
            foreach ( $indexes as $idx )
              if ( $idx >= $i )
              {
                $validRequests[] = &$requests[$idx];
                $processedRequestsIndexes[$idx] = true;
              }

            // perform the request          
            try
            {
              // reload auth    
      			  $last_token_auth = self::reloadAuthUsingTokenAuth($validRequests[0],$last_token_auth);
      			  
        			// call the method 
        			$returned = Piwik_API_Proxy::getInstance()->call($moduleClass, $method, &$validRequests, true);
        			// add result to the results collection, with same order
              $returnedValues = array_merge($returnedValues,$returned);   		
      		  } catch(Exception $e ) {
      		    // capture the exception as response 
      		    $toReturn = $response->getResponseException( $e );
              // stop iterating over the groups            
      		    break;
        		}

            // stop iterating over the groups            
            break;
          }
        }
        // if a response has been captured - will happen if an exception occured
        // during the grouping requests
        if ( $toReturn !== null )
          break;
        // skip current index if its been processed by the group requests
        if ( isset($processedRequestsIndexes[$i]) )
          continue;

        // set current request       
        $request = &$requests[$i];
        // mark request index as processed
        $processedRequestsIndexes[$i] = true;

        // perform the request        
    		try {
    			// read parameters
    			$moduleMethod = Piwik_Common::getRequestVar('method', null, null, $request);
    			
    			list($module, $method) = $this->extractModuleAndMethod($moduleMethod); 
    			
    			if(!Piwik_PluginsManager::getInstance()->isPluginActivated($module))
    			{
    				throw new Piwik_FrontController_PluginDeactivatedException($module);
    			}
    			$moduleClass = "Piwik_" . $module . "_API";
    
    			$last_token_auth = self::reloadAuthUsingTokenAuth($request,$last_token_auth);
    			
    			// call the method 
    			$returnedValue = Piwik_API_Proxy::getInstance()->call($moduleClass, $method, $request);
          $returnedValues[] = $returnedValue;   		
  		  } catch(Exception $e ) {
  		    $toReturn = $response->getResponseException( $e );
  		    // in case of exception, stop processing the requests
  		    break;
    		}
      }

      // if no response has been captured (i.e., no exception occured)
      if ( $toReturn === null )
        // construct a response from the returned values of the API requests  		
        $toReturn = $response->getResponse($returnedValues, null, null);
    }
    else
    {
  		// create the response
  		$response = new Piwik_API_ResponseBuilder($outputFormat, $this->request);
    
  		try {
  			// read parameters
  			$moduleMethod = Piwik_Common::getRequestVar('method', null, null, $this->request);
  			
  			list($module, $method) = $this->extractModuleAndMethod($moduleMethod); 
  			
  			if(!Piwik_PluginsManager::getInstance()->isPluginActivated($module))
  			{
  				throw new Piwik_FrontController_PluginDeactivatedException($module);
  			}
  			$moduleClass = "Piwik_" . $module . "_API";
  
  			self::reloadAuthUsingTokenAuth($this->request);
  			
  			// call the method 
  			$returnedValue = Piwik_API_Proxy::getInstance()->call($moduleClass, $method, $this->request);
  			$toReturn = $response->getResponse($returnedValue, $module, $method);
  		} catch(Exception $e ) {
  			$toReturn = $response->getResponseException( $e );
  		}
    }    
  	
		return $toReturn;
	}

	/**
	 * If the token_auth is found in the $request parameter, 
	 * the current session will be authenticated using this token_auth.
	 * It will overwrite the previous Auth object.
	 * 
	 * @param array  $request  If null, uses the default request ($_GET)
	 * @return void
	 */
	static public function reloadAuthUsingTokenAuth($request = null,$last_token_auth = null)
	{
		// if a token_auth is specified in the API request, we load the right permissions
		$token_auth = Piwik_Common::getRequestVar('token_auth', '', 'string', $request);
		if ( ($last_token_auth !== null) && ( $last_token_auth == $token_auth ) )
		  return $token_auth;
		  
		if($token_auth)
		{
			Piwik_PostEvent('API.Request.authenticate', $token_auth);
			Zend_Registry::get('access')->reloadAccess();
			Piwik::raiseMemoryLimitIfNecessary();
		}
		return $token_auth;		
	}

	/**
	 * Returns array( $class, $method) from the given string $class.$method
	 *
	 * @param string  $parameter
	 * @throws Exception
	 * @return array
	 */
	private function extractModuleAndMethod($parameter)
	{
		$a = explode('.',$parameter);
		if(count($a) != 2)
		{
			throw new Exception("The method name is invalid. Expected 'module.methodName'");
		}
		return $a;
	}
	
	/**
	 * Helper method to process an API request using the variables in $_GET and $_POST.
	 * 
	 * @param string $method The API method to call, ie, Actions.getPageTitles
	 * @param array $paramOverride The parameter name-value pairs to use instead of what's
	 *                             in $_GET & $_POST.
	 * @param mixed The result of the API request.
	 */
	public static function processRequest( $method, $paramOverride = array() )
	{
		// set up request params
		$params = $_GET + $_POST;
		$params['format'] = 'original';
		$params['module'] = 'API';
		$params['method'] = $method;
		$params = $paramOverride + $params;
		
		// process request
		$request = new Piwik_API_Request($params);
		return $request->process();
	}
}
