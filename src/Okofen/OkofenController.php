<?php
namespace Okofen;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class OkofenController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $factory = $app['controllers_factory'];
        
        $factory->post('/get-data', 'Okofen\OkofenController::getData');
        
        return $factory;
    }

    public function getData(Application $app, Request $request)
    {
        
        $data = json_decode($request->getContent(), true);

        $username = $data["username"];
        $password = $data["password"];
        //$username = $request->request->get('username', null);
        //$password = $request->request->get('password', null);

        $post_data = array(
            "username" => $username,
            "password" => $password,
            "redirect_to_plant" => "checked",
            "login_as" => "customer",
            "submit" => 'Login'
        );
        
        //print_r($post_data);
        
        $url = self::getUrl($app, $post_data);
        
        $columns = self::getLogColumns($url);
        
        return $app->json($columns);
        
        //$subRequest = Request::create('/', 'POST', array('url'=>$url, 'username'=>$username, 'password'=>$password));
        
        //return $app->handle($subRequest, HttpKernelInterface::MASTER_REQUEST);
    }
    
    private function getUrl(Application $app, $post_data){
        
        $URL ="https://my.oekofen.info/includes/process_login.php";
       
        $content = self::getContent($URL, $post_data);
                
        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        
        $xpath = new \DOMXPath($dom);
        
        $data = array();
        
        $action = '';
        
        $forms = $xpath->query('//form');
        
        foreach ($forms as $form) {
            if ($name = $form->getAttribute('name')) {
                $action = $form->getAttribute('action');
            }
        }
        
        // remove index.cgi
        $haystack = $action;
        $needle = '/index.cgi';
        $index_cgi_pos = strpos($haystack, $needle);
        $url = substr($action, 0, $index_cgi_pos);
       
        return $url;
    }
    
    private function getLogColumns($URL){
        
        $date = date('Ymd');              
        $file = 'touch_'.$date.'.csv';
        
        $logfile = $URL . '/logfiles/pelletronic/'.$file;
        
        //echo $logfile;
        
        //$logfile = 'http://192.168.10.149:8080/logfiles/pelletronic/'.$path;
        $log_data_raw = self::getContent($logfile, null);
        
        $line = strstr($log_data_raw,"\n",true); //get first line with columnheaders
        $line_items = explode(';', $line);
        
        $columns = array();
                
        foreach($line_items as $i){
            
            $i = preg_replace("/\[(.*)\]/", "", $i);
            $i = rtrim($i);
            $i = preg_replace('/\s+/', '_',  $i);
        
            //if(== "PE.")
            //if(preg_match('PE[0-9]_', ));
            if (preg_match("/PE[0-9]_/", substr($i, 0, 4))){ $columns['PE'][] = $i; }
            elseif (preg_match("/HK[0-9]_/", substr($i, 0, 4))){ $columns['HK'][] = $i; }
            elseif (preg_match("/PU[0-9]_/", substr($i, 0, 4))){ $columns['PU'][] = $i; }
            elseif (preg_match("/WW[0-9]_/", substr($i, 0, 4))){ $columns['WW'][] = $i; }
            elseif (preg_match("/Zirkp[0-9]_/", substr($i, 0, 7))){ $columns['ZIRK'][] = $i; }
            elseif (preg_match("/Zubrp[0-9]_/", substr($i, 0, 7))){ $columns['ZUBR'][] = $i; }
            elseif (preg_match("/KT_/", substr($i, 0, 4))){ $columns['KT'][] = $i; }
            else $columns['GENERAL'][] = $i; 
        }
        
        return $columns;
        
    }
    
    private function getContent($URL, $post_data){
        
        $fields = empty($post_data) ? '' : http_build_query($post_data);
        
        $options = array();
        
        $options[CURLOPT_URL] = (string) $URL;
        if(ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) $options[CURLOPT_FOLLOWLOCATION] = true;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_TIMEOUT] = 10;
        $options[CURLOPT_POSTFIELDS] = $fields;
        $options[CURLOPT_POST] = count($post_data);
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        
        // init
        $curl = curl_init();
        
        // set options
        curl_setopt_array($curl, $options);
        
        // execute
        $response = curl_exec($curl);
        
        // fetch errors
        $errorNumber = curl_errno($curl);
        $errorMessage = curl_error($curl);
        
        // close
        curl_close($curl);
        
        // validate
        if($errorNumber != '') throw new \Exception($errorMessage);
        
        return $response;
    }
}