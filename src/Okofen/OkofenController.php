<?php
namespace Okofen;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Util\SecureRandom;

class OkofenController implements ControllerProviderInterface
{

    public function connect(Application $app)
    {
        $factory = $app['controllers_factory'];
        
        $factory->post('/get-data', 'Okofen\OkofenController::getData');
        $factory->post('/add-column', 'Okofen\OkofenController::addColumnToSession');
        $factory->get('/plot', 'Okofen\OkofenController::plot');
        
        return $factory;
    }

    public function addColumnToSession(Application $app, Request $request)
    {
        $columnsToShow = $app['session']->get('columnsToShow');
        
        $data = json_decode($request->getContent(), true);
        $col = $data['col'];
        $checked = $data['checked'];
        
        if($checked == 1){
            $columnsToShow[] = $col;
        }else{
            $columnsToShow = array_diff($columnsToShow, array($col));
        }
        
        $app['session']->set('columnsToShow', $columnsToShow);
        
        return $app->json($data['col'].' - '. $checked);
    }

    public function getData(Application $app, Request $request)
    {
        $data = json_decode($request->getContent(), true);
        
        $username = $data["username"];
        $password = $data["password"];
        
        $app['session']->set('username', $username);
        
        $post_data = array(
            "username" => $username,
            "password" => $password,
            "redirect_to_plant" => "checked",
            "login_as" => "customer",
            "submit" => 'Login'
        );
        
        $url = self::getUrl($app, $post_data);
        
        $app['session']->set('url', $url);
        
        $columns = self::getLogColumns($app, $url);
        
        return $app->json($columns);
        
        // $subRequest = Request::create('/', 'POST', array('url'=>$url, 'username'=>$username, 'password'=>$password));
        
        // return $app->handle($subRequest, HttpKernelInterface::MASTER_REQUEST);
    }

    /**
     * Plot graph
     *
     * @param Application $app            
     * @return boolean
     */
    public function plot(Application $app)
    {
        $random = rand(0, 9999);
        
        $date = date('Ymd');
        $file = 'touch_' . $date . '_' . $random . '.png';
        
        $username = $app['session']->get('username');
        $path = '../data/touch/' . $username . '/images';
        
        $plot = new \PHPlot(1400, 900);
        $plot->SetImageBorderType('plain');
        $plot->SetPlotType('lines');
        $plot->SetXTickLabelPos('none');
        $plot->SetXTickPos('none');
        
        $columnsToShow = $app['session']->get('columnsToShow');
        
        $log_data = self::getPlotData($app);
        
        $toShow = array();
        
        foreach ($log_data as $log) {
            $vals = array();
            $vals[] = $log['Zeit'];
            foreach ($columnsToShow as $c)
                $vals[] = str_replace(',', '.', $log[$c]);
            $toShow[] = $vals;
        }
        
        $plot->SetDataValues($toShow);
        $plot->SetLegend($columnsToShow);
        $plot->SetPlotAreaWorld(NULL, 0, NULL, NULL);
        
        // Don't draw the image yet
        // $plot->SetPrintImage(0);
        // Don't output the file headers - we're inline!
        $plot->SetIsInline("1");
        
        // $plot->SetIsInline(true);
        $plot->SetOutputFile($path . '/' . $file);
        
        $plot->DrawGraph();
        
        return $app->json($path . '/' . $file); // $app->stream($stream, 200, array('Content-Type' => 'image/png'));
                                                    
        // return
    }

    private function getPlotData(Application $app)
    {
        $url = $app['session']->get('url');
        
        $log_data_raw = self::getLog($url);
        $columns = $app['session']->get('columns');
        
        // print_r($columns);
        
        $log_data = \SpoonFileCSV::stringToArray($log_data_raw, $columns, null, ';');
        
        $date = date('Ymd');
        $file = 'touch_' . $date . '.csv';
        $username = $app['session']->get('username');
        $download_path = '../data/touch/' . $username . '/files';
        
        \SpoonFileCSV::arrayToFile($download_path . '/' . $file, $log_data, $columns, null, ';');
        
        return $log_data;
    }

    private function getUrl(Application $app, $post_data)
    {
        $URL = "https://my.oekofen.info/includes/process_login.php";
        
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

    /**
     * get Raw log data
     *
     * @param string $URL            
     * @return raw log data
     */
    private function getLog($URL)
    {
        $date = date('Ymd');
        $file = 'touch_' . $date . '.csv';
        
        $logfile = $URL . '/logfiles/pelletronic/' . $file;
        
        $log_data_raw = self::getContent($logfile, null);
        
        return $log_data_raw;
    }

    /**
     * get columns to log
     *
     * @param Application $app            
     * @param string $URL            
     * @return Ambigous <multitype:, unknown, mixed>
     */
    private function getLogColumns(Application $app, $URL)
    {
        
        // echo $logfile;
        
        // $logfile = 'http://192.168.10.149:8080/logfiles/pelletronic/'.$path;
        $log_data_raw = self::getLog($URL);
        
        $line = strstr($log_data_raw, "\n", true); // get first line with columnheaders
        $line_items = explode(';', $line);
        
        $columns = array();
        $columns_unsorted = array();
        
        foreach ($line_items as $i) {
            
            $i = preg_replace("/\[(.*)\]/", "", $i);
            $i = rtrim($i);
            $i = preg_replace('/\s+/', '_', $i);
            
            if (preg_match("/PE[0-9]_/", substr($i, 0, 4))) {
                $columns['PE'][] = $i;
            } elseif (preg_match("/HK[0-9]_/", substr($i, 0, 4))) {
                $columns['HK'][] = $i;
            } elseif (preg_match("/PU[0-9]_/", substr($i, 0, 4))) {
                $columns['PU'][] = $i;
            } elseif (preg_match("/WW[0-9]_/", substr($i, 0, 4))) {
                $columns['WW'][] = $i;
            } elseif (preg_match("/Zirkp[0-9]_/", substr($i, 0, 7))) {
                $columns['ZIRK'][] = $i;
            } elseif (preg_match("/Zubrp[0-9]_/", substr($i, 0, 7))) {
                $columns['ZUBR'][] = $i;
            } elseif (preg_match("/KT_/", substr($i, 0, 4))) {
                $columns['KT'][] = $i;
            } else
                $columns['GENERAL'][] = $i;
            
            $columns_unsorted[] = $i;
        }
        
        $app['session']->set('columns', $columns_unsorted);
        
        return $columns;
    }

    private function getContent($URL, $post_data)
    {
        $fields = empty($post_data) ? '' : http_build_query($post_data);
        
        $options = array();
        
        $options[CURLOPT_URL] = (string) $URL;
        if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off'))
            $options[CURLOPT_FOLLOWLOCATION] = true;
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
        if ($errorNumber != '')
            throw new \Exception($errorMessage);
        
        return $response;
    }
}