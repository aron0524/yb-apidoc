<?php
declare(strict_types = 1);

namespace yuanbo\apidoc\parseApi;

use think\facade\App;
use think\facade\Db;
use yuanbo\apidoc\exception\ErrorException;
use yuanbo\apidoc\Utils;
use think\facade\Config;

class ParseMarkdown
{
    protected $config = [];
    protected $system;//当前系统架构
    public function __construct()
    {
        $this->config = Config::get('apidoc')?Config::get('apidoc'):Config::get('apidoc.');
        $this->system = env('app.system');
    }

    /**
     * 获取md文档菜单
     * @return array
     */
    public function getDocsMenu(): array
    {
        $config  = $this->config;
        $docData = [];
        if (!empty($config['docs']) && count($config['docs']) > 0) {
            $docData = $this->handleDocsMenuData($config['docs']);
        }
        if ($this->system == 'DAML' || $this->system == 'TIPY'){
            try {
                //动态加载开发文档
                $appKey     =   $_REQUEST['appKey'];
                $appKey     =   isset($appKey) ? explode(',',$appKey) : [];
                $appKey     =   isset($appKey) ? $appKey[0] : [];
                $appKey     =   isset($appKey) ? explode('_',$appKey) : [];
                $appKey     =   isset($appKey) ? strtoupper($appKey[2]) : [];
                $doc_table  =   '';
                if ($this->system == 'DAML'){
                    $doc_table = 'daml_apim_doc';
                }else{
                    $doc_table = 'tipy_tdpm_doc';
                }
                $apps       =   Db::table(['daml_apim_apps a',$doc_table .' d'])
                    ->where('a.status', '=',1)
                    ->where('a.code', '=',$appKey)
                    ->where('d.system_id = a.id')
                    ->where('d.status = 1')
                    ->field(['a.id','a.name','d.system_id','d.doc_name','d.doc_filepath','d.id as docid'])
                    ->select()->toArray();
                if ($apps){
                    $docTitle =  $apps[0]['name'];
                    foreach ($apps as $key=>$val){
                        $result['title']            =   $docTitle;
                        $result['menu_key']         =   Utils::createRandKey($appKey . "_");
                        //生成配置
                        $result['children'][]       =   [
                            'title'=>$val['doc_name'],
                            'path'=>$val['doc_filepath'],
                            'type'=>'md',
                            'menu_key'=>Utils::createRandKey($appKey . "_" . $val['docid'])
                        ];

                    }
                    array_push($docData, $result);
                }
            }
            catch (ErrorException $e) {
                return Utils::showJson($e->getCode(),$e->getMessage());
            }
        }
        return $docData;
    }

    /**
     * 处理md文档菜单数据
     * @param array $menus
     * @return array
     */
    protected function handleDocsMenuData(array $menus): array
    {
        $list = [];
        foreach ($menus as $item) {
            if (!empty($item['children']) && count($item['children']) > 0) {
                $item['children']    = $this->handleDocsMenuData($item['children']);
                $item['menu_key'] = Utils::createRandKey("md_group");
                $list[]           = $item;
            } else {
                $item['type']     = 'md';
                $item['title']     = Utils::getLang($item['title']);
                $item['menu_key'] = Utils::createRandKey("md");
                $list[]           = $item;
            }
        }
        return $list;
    }


    /**
     * 获取md文档内容
     * @param string $appKey
     * @param string $path
     * @return string
     */
    public function getContent(string $appKey, string $path,$lang="")
    {
        if (!empty($appKey)){
            $currentApps = (new Utils())->getCurrentApps($appKey);
            $fullPath      = (new Utils())->replaceCurrentAppTemplate($path, $currentApps);
        }else{
            $fullPath = $path;
        }
        $fullPath = Utils::replaceTemplate($fullPath,[
            'lang'=>$lang
        ]);

        if (strpos($fullPath, '#') !== false) {
            $mdPathArr = explode("#", $fullPath);
            $mdPath=$mdPathArr[0];
            $mdAnchor =$mdPathArr[1];
        } else {
            $mdPath = $fullPath;
            $mdAnchor="";
        }
        $fileSuffix = "";
        if (strpos($fullPath, '.md') === false) {
            $fileSuffix = ".md";
        }
        $filePath    = App::getRootPath() . $mdPath . $fileSuffix;
        $contents    = Utils::getFileContent($filePath);
        if ($this->system == 'DAML' || $this->system == 'TIPY') {
            //读取远程文件
            if (empty($contents)) {
                $contents = Utils::getFileContents($mdPath . $fileSuffix);
            }
        }
        // 获取指定h2标签内容
        if (!empty($mdAnchor)){
            if (strpos($contents, '## ') !== false) {
                $contentArr = explode("\r\n", $contents);
                $contentText = "";
                foreach ($contentArr as $line){
                    $contentText.="\r\n".trim($line);
                }
                $contentArr = explode("\r\n## ", $contentText);
                $content="";
                foreach ($contentArr as $item){
                    $itemArr = explode("\r\n", $item);
                    if (!empty($itemArr) && $itemArr[0] && $mdAnchor===$itemArr[0]){
                        $content = str_replace($itemArr[0]."\r\n", '', $item);
                        break;
                    }
                }
                return $content;
            }
        }
        return $contents;
    }


}