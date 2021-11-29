<?php
namespace Otdr\MageApiSubiektGt\Helper;

class SubiektApi
{
    private $api_key;
    private $api_endpoint = '';
    private $verify_ssl   = false;
    private $result = false;

    /**
     * Create a new instance
     */
    function __construct($api_key,$api_endpoint){        
        $this->api_key = $api_key;       
        $this->api_endpoint = $api_endpoint;
    }

    /**
     * Call request to api  
     */
    public function call($method, $args=array(),$debug = false, $timeout = 60){
        return $this->makeRequest($method, $args,$debug, $timeout);
    }

    /**
     * Performs the underlying HTTP request. Not very exciting
     */
    private function makeRequest($method, $args=array(),$debug = false, $timeout = 60){      
        $request_data['api_key'] = $this->api_key;
        $request_data['data'] = $args;
        $url = $this->api_endpoint.'/'.$method;
        
        if (function_exists('curl_init') && function_exists('curl_setopt')){
            if($debug){
                print_r($request_data);
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-SUBIEKT-API/1.0');       
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
            $result = curl_exec($ch);
            $this->result  = $result;
            if($debug == true){
                print_r($result);
            }
        } else {
            $json_data = json_encode($args);
            $result    = file_get_contents($url, null, stream_context_create(array(
                'http' => array(
                    'protocol_version' => 1.1,
                    'user_agent'       => 'PHP-SUBIEKT-API/1.0',
                    'method'           => 'POST',
                    'header'           => "Content-type: application/json\r\n".
                                          "Connection: close\r\n" .
                                          "Content-length: " . strlen($json_data) . "\r\n",
                    'content'          => $json_data,
                ),
            )));
        }

        return $result ? json_decode($result, true) : false;
    }

    public function getPlainTextResult(){
        return $this->result;
    }
}
?>