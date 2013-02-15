<?
class Piwik_API_Bulk_Analyzer 
{
  private $groupingRules;
  
  function __construct($groupingRules = array())
  {
    $this->groupingRules = $groupingRules;
  }
  
	public function analyzeBulkAPIFromRequests($requests)
	{
	 $groups = array();
	
	 // iterate over requests	 	 
	 $idx = array();
	 foreach ( $requests as $id=>$request )
	 {
	   if ( isset($request["method"]) )
	   {
	     $method = $request["method"];
       if ( isset($this->groupingRules[$method]) )
       {
          if ( !isset($groups[$method]) )
            $groups[$method] = array();
          $groups[$method][] = $id;
       }           
	   }	   
   }
	 
	 $ret = array();
	 
	 foreach ( $groups as $method => $indexes )
	   $ret[] = array_merge(array($indexes),$this->groupingRules[$method]);
	   
	 return $ret;
  }
  
  protected function mapResultsToRequestsByKey($requests,$results,$requestKey,$resultKey)
  {
    $resKeys = array();
    foreach ( $results as &$result )
    {
      if ( isset($result[$resultKey]) )
        $resKeys[$result[$resultKey]] = $result;
    }
    
    $ret = array();
    foreach ( $requests as &$request )
    {
      if ( $requestKey === false )
      {
        if ( isset( $resKeys[$request] ) )
          $ret[] = $resKeys[$request];      
        else
          $ret[] = false;
      }
      else
      {
        if ( isset( $request[$requestKey] ) && isset( $resKeys[$request[$requestKey]] ) )
          $ret[] = $resKeys[$request[$requestKey]];      
        else
          $ret[] = false;
      }
    }
    
    return $ret;
  }  
}
?>