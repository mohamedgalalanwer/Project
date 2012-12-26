<?php

/**
 * Mysqli implementation of the database Query
 */
class Query_Mysql_Driver extends Query_Database{
	
    /**
     * If a string is passed escapes a field by enclosing it in `` quotes.
	 * If you pass an Expression_Database object the value will be inserted into the query uneascaped
     * 
     * @param mixed $field     Field to be escaped or an Expression_Database object
	 *                         if the field must not be escaped
     * @return string  Escaped field representation
     * @access public 
	 * @see Expression_Database
     */
	public function escape_field($field) {
		if (is_object($field) && get_class($field) == 'Expression_Database')
			return $field->value.' ';
		$field = explode('.', $field);
		if (count($field) == 1)
			array_unshift($field,$this->lastAlias());
		$str = '`'.$field[0].'`.';
		if (trim($field[1]) == '*')
			return $str.'* ';
		return $str."`{$field[1]}` ";
	}
	
    /**
     * Replaces the value with ? and appends it to the parameters array
	 * If you pass an Expression_Database object the value will be inserted into the query uneascaped
     * @param mixed $val     Value to be escaped or an Expression_Database object
	 *                       if the value must not be escaped
     * @param array  &$params Reference to parameters array
     * @return string  Escaped value representation
     * @access public 
     */
	public function escape_value($val,&$params) {
		if (is_object($val) && get_class($val) == 'Expression_Database')
			return $val->value.' ';
		$params[] = $val;
		return '? ';
	}
	
    /**
     * Builds a query and fills the $params array with parameter values
     * 
     * @return array     An array with a prepared query string and an array of parameters
     * @access public    
     */
	public function query() {
		
		$query = '';
		$params = array();
		if ($this->_type == 'insert') {
			$query.= "INSERT INTO `{$this->_table}`";
			$columns = '';
			$values = '';
			$first = true;
			foreach($this->_data as $key => $val) {
				if (!$first) {
					$values.= ',';
					$columns.= ',';
				}else {
					$first=false;
				}
				$columns.= "`{$key}` ";
				$values.=$this->escape_value($val,$params);
			}
			$query.= "({$columns}) VALUES({$values})";
		}else{
			if ($this->_type == 'select'){
				$query.= "SELECT ";
				if($this->_fields==null){
					$query.= "* ";
				}else{
					$first = true;
					foreach($this->_fields as $f) {
						if (!$first) {
							$query.=", ";
						}else {
							$first = false;
						}
						$query.="{$this->escape_field($f)} ";
					}
				}
				$query.= "FROM `{$this->_table}` ";
			}
			if ($this->_type == 'count') {
				$query.= "SELECT COUNT(*) as `count` FROM `{$this->_table}` ";	
			}
			if($this->_type=='delete')
				$query.= "DELETE FROM `{$this->_table}` ";
			if($this->_type=='update'){
				$query.= "UPDATE `{$this->_table}` SET ";
				$first = true;
				foreach($this->_data as $key=>$val){
					if (!$first) {
						$query.=',';
					}else {
						$first=false;
					}
					$query.= "`{$key}`=".$this->escape_value($val,$params);
				}
			}
			
			foreach($this->_joins as $join) {
				$table = $join[0];
				if (is_array($table)){
					$table = "`{$table[0]}` as `{$table[1]}`";
				}else {
					$table="`{$table}`";
				}
				$query.= strtoupper($join[1])." JOIN {$table} ON ".$this->get_condition_query($join[2],$params,true,true);
			}

			if (!empty($this->_conditions)) {
				$query.="WHERE ".$this->get_condition_query($this->_conditions,$params,true);
			}
			if (($this->_type == 'select' ||  $this->_type == 'count') && $this->_groupby!=null) {
				$query.="GROUP BY ".$this->escape_field($this->_groupby);
			}
			if (($this->_type == 'select' ||  $this->_type == 'count') && !empty($this->_having)) {
				$query.="HAVING ".$this->get_condition_query($this->_having,$params,true);
			}
			
			if ($this->_type == 'select' && !empty($this->_orderby)) {
				$query.="ORDER BY ";
				$first = true;
				foreach($this->_orderby as $order) {
					if (!$first) {
						$query.=',';
					}else {
						$first=false;
					}
					$query.= $this->escape_field($order[0]);
					if (isset($order[1])) {
						$dir = strtoupper($order[1]);
						$query.=$dir." ";
					}
				}
			}
			if($this->_type != 'count'){
				if ($this->_limit != null)
					$query.= "LIMIT {$this->_limit} ";
				if ($this->_offset != null)
					$query.= "OFFSET {$this->_offset} ";
			}
			
		}
		
		return array($query,$params);
	}

    /**
     * Recursively parses conditions array into a query string
     * 
     * @param array     $p                   Element of the array of conditions
     * @param array   &$params             Reference to parameters array
     * @param boolean   $skip_first_operator Flag to skip the first logical operator in a query
	 *                                       to prevent AND or OR to be at the beginning of the query
     * @param boolean   $value_is_field      Flag if the the value in the logical operations should
	 *                                       be treated as a field. E.g. for joins where the fields are 
	 *                                       compared between themselves and not with actual values
     * @return string     String representation of the conditions
     * @access public    
     * @throws Exception If condition cannot be parsed
     */
	public function get_condition_query($p,&$params,$skip_first_operator,$value_is_field=false) {
		if (isset($p['field'])) {
			if ($value_is_field){
				$param = $this->escape_field($p['value']);
			}else {
				$param = $this->escape_value($p['value'],$params);
			}
			return $this->escape_field($p['field']).' '.$p['operator'].' '.$param;
		}
		if (isset($p['logic'])) {
			return ($skip_first_operator?'':strtoupper($p['logic'])).' '
					.$this->get_condition_query($p['conditions'],$params,false,$value_is_field);
		}
		
		$conds = '';
		$skip=$skip_first_operator||(count($p) > 1);
		foreach($p as $q) {
			$conds.=$this->get_condition_query($q,$params,$skip,$value_is_field);
			$skip=false;
		}
		if (count($p) > 1 && !$skip_first_operator)
			return "( ".$conds." ) ";
		return $conds;

		throw new Exception("Cannot parse condition:\n".var_export($p,true));
	}



}