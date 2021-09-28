<?php
declare(strict_types = 1);

namespace yuanbo\apidoc;

use http\Env;
use yuanbo\apidoc\exception\AuthException;
use yuanbo\apidoc\exception\ErrorException;
use yuanbo\apidoc\parseApi\CacheApiData;
use yuanbo\apidoc\parseApi\ParseAnnotation;
use yuanbo\apidoc\parseApi\ParseMarkdown;
use think\App;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Request;
use think\facade\Db;

class Controller
{
    protected $app;

    protected $config;

    protected $system;//当前系统架构

    /**
     * @var int tp版本
     */
    protected $tp_version;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->tp_version = substr(\think\facade\App::version(), 0, 2) == '5.'? 5: 6;
        $config = Config::get("apidoc")?Config::get("apidoc"):Config::get("apidoc.");
        if (!(!empty($config['apps']) && count($config['apps']))){
            $default_app = Config::get("app.default_app")?Config::get("app.default_app"):Config::get("app.default_module");
            $namespace = \think\facade\App::getNamespace();
            // tp5获取 application
            if ($this->tp_version === 5){
                $appPath = \think\facade\App::getAppPath();
                $appPathArr = explode("\\", $appPath);
                for ($i = count($appPathArr)-1; $i>0 ;$i--){
                    if ($appPathArr[$i]){
                        $namespace = $appPathArr[$i];
                        break;
                    }
                }
            }
            $path = $namespace.'\\'.$default_app.'\\controller';
            if (!is_dir($path)){
                $path =$namespace.'\\controller';
            }
            $defaultAppConfig = ['title'=>$default_app,'path'=>$path,'folder'=>$default_app];
            $config['apps'] = [$defaultAppConfig];
        }
        Config::set(['apidoc'=>$config]);
        $this->config = $config;
        $this->system = env('app.system');
    }

    /**
     * 获取配置
     * @return \think\response\Json
     */
    public function getConfig(){
        $config = $this->config;

        // 根据当前系统架构判断 模型层&第三方
        if ($this->system ==  'DAML' || $this->system ==  'TIPY'){
            // 修改配置文件从数据库生成
            $apps           = $this->getSystemConfig();
            $appKey         = $apps[0]['folder'] . "," . $apps[0]['items'][0]['folder'];
            $config['apps'] = $apps;
        }
        // 业务应用层
        if ($this->system ==  'DAML')
        {
            $rpc_path = 'rpc\contract\model\DataRpcInterface';
            if (file_exists($rpc_path) == false){
                require_once $rpc_path;
                $model          = new DataRpcInterface();
                $apps           = $model->apidoc();
                $appKey         = $apps[0]['folder'] . "," . $apps[0]['items'][0]['folder'];
                $config['apps'] = $apps;
            }
        }

        if (!empty($config['auth'])){
            unset($config['auth']['password']);
            unset($config['auth']['key']);
        }
        $request = Request::instance();
        $params = $request->param();

        if (!empty($params['lang'])){
            if ($this->tp_version === 5){
                Lang::setLangCookieVar($params['lang']);
            }else{
                \think\facade\App::loadLangPack($params['lang']);
            }

        }
        $config['headers'] = Utils::getArrayLang($config['headers'],"desc");
        $config['parameters'] = Utils::getArrayLang($config['parameters'],"desc");
        $config['responses'] = Utils::getArrayLang($config['responses'],"desc");


        // 清除apps配置中的password
        $config['apps'] = (new Utils())->handleAppsConfig($config['apps'],true);
        return Utils::showJson(0,"",$config);
    }

    /**
     * 验证密码
     * @return false|\think\response\Json
     * @throws \think\Exception
     */
    public function verifyAuth(){
        $config = $this->config;

        $request = Request::instance();
        $params = $request->param();
        $password = $params['password'];
        if (empty($password)){
            throw new AuthException( "password not found");
        }
        $appKey = !empty($params['appKey'])?$params['appKey']:"";

        if (!$appKey && !(!empty($config['auth']) && $config['auth']['enable'])) {
            return false;
        }
        try {
            $hasAuth = (new Auth())->verifyAuth($password,$appKey);
            $res = [
                "token"=>$hasAuth
            ];
            return Utils::showJson(0,"",$res);
        } catch (AuthException $e) {
            return Utils::showJson($e->getCode(),$e->getMessage());
        }

    }

    /**
     * 获取文档数据
     * @return \think\response\Json
     */
    public function getApidoc(){

        $config = $this->config;
        $request = Request::instance();
        $params = $request->param();
        $lang = "";

        if (!empty($params['lang'])){
            $lang = $params['lang'];
            if ($this->tp_version === 5){
                Lang::setLangCookieVar($lang);
            }else{
                \think\facade\App::loadLangPack($lang);
            }

        }

        if (!empty($params['appKey'])){
            // 获取指定应用
            $appKey = $params['appKey'];
        }else{
            // 获取默认控制器
            $default_app = $config = Config::get("app.default_app");
            $appKey = $default_app;

        }
        $currentApps = (new Utils())->getCurrentApps($appKey);
        $currentApp  = $currentApps[count($currentApps) - 1];

        (new Auth())->checkAuth($appKey);

        $cacheData=null;
        if (!empty($config['cache']) && $config['cache']['enable']){
            $cacheKey = $appKey."_".$lang;
            $cacheData = (new CacheApiData())->get($cacheKey);
            if ($cacheData && empty($params['reload'])){
                $apiData = $cacheData;
            }else{
                // 生成数据并缓存
                $apiData = (new ParseAnnotation())->renderApiData($appKey);
                (new CacheApiData())->set($cacheKey,$apiData);
            }
        }else{
            // 生成数据
            $apiData = (new ParseAnnotation())->renderApiData($appKey);
        }

        // 接口分组
        if (!empty($currentApp['groups'])){
            $data = (new ParseAnnotation())->mergeApiGroup($apiData['data'],$currentApp['groups']);
        }else{
            $data = $apiData['data'];
        }
        $groups=!empty($currentApp['groups'])?$currentApp['groups']:[];
        $json=[
            'data'=>$data,
            'app'=>$currentApp,
            'groups'=>$groups,
            'tags'=>$apiData['tags'],
        ];

        return Utils::showJson(0,"",$json);
    }

    public function getMdMenus(){
        // 获取md
        $request = Request::instance();
        $params = $request->param();

        if (!empty($params['lang'])){
            if ($this->tp_version === 5){
                Lang::setLangCookieVar($params['lang']);
            }else{
                \think\facade\App::loadLangPack($params['lang']);
            }
        }
        $docs = (new ParseMarkdown())->getDocsMenu();
        return Utils::showJson(0,"",$docs);

    }

    /**
     * 获取md文档内容
     * @return \think\response\Json
     */
    public function getMdDetail(){
        $request = Request::instance();
        $params = $request->param();
        if (!empty($params['lang'])){
            if ($this->tp_version === 5){
                Lang::setLangCookieVar($params['lang']);
            }else{
                \think\facade\App::loadLangPack($params['lang']);
            }
        }
        try {
            if (empty($params['path'])){
                throw new ErrorException("mdPath not found");
            }
            if (empty($params['appKey'])){
                throw new ErrorException("appkey not found");
            }
            $lang="";
            if (!empty($params['lang'])){
                $lang=$params['lang'];
            }
            (new Auth())->checkAuth($params['appKey']);
            $content = (new ParseMarkdown())->getContent($params['appKey'],$params['path'],$lang);
            $res = [
                'content'=>$content,
            ];
            return Utils::showJson(0,"",$res);

        } catch (ErrorException $e) {
            return Utils::showJson($e->getCode(),$e->getMessage());
        }
    }

    /**
     * @title                           获取当前系统架构配置
     * @description                     获取当前系统架构配置
     * @author                          袁波
     * @adddate                         2021/9/28
     * @lasteditTime                    2021/9/28
     */
    public function getSystemConfig(){
        $apps       = $this->system;//获取系统架构层
        // 查询分类同步表
        $field      = ['id','pid','name as label','code as value'];
        $children = Db::table('csap_sys_code')
            ->where('soft_del', '=',1)
            ->field($field)
            ->select()->toArray();
        $list  = [];
        //递归根据不同类型返回不同树结构
        $list = $this->tree_cate($children);
        // 业务应用层
        if ($this->system == 'BSAP'){
            $list = $list[0];
        }
        // 系统公共层
        if ($this->system == 'SYSC'){
            $list = $list[1];
        }
        // 数据模型层
        if ($this->system == 'DAML'){
            $list = $list[2];
        }
        // 第三方接口层
        if ($this->system == 'TIPY'){
            $list = $list[3];
        }
        $config = [];
        // 获取系统版本
        $version = $this->getSystemVersion();
        foreach ($list['children'] as $key=>$value){
            foreach ($value['children'] as $child) {
                if ($child['level']==3){
                    foreach ($version as $k=>$v){
                        $child['version'][] = $v;
                    }
                    // 业务应用层
                    if ($this->system == 'BSAP'){
                        $child['code'] = strtolower('DAML_APIM_'.$child['value']);
                    }
                    // 系统公共层
                    if ($this->system == 'SYSC'){
                        $child['code'] = strtolower('DAML_APIM_'.$child['value']);
                    }
                    // 数据模型层
                    if ($this->system == 'DAML'){
                        $child['code'] = strtolower('DAML_APIM_'.$child['value']);
                    }
                    // 第三方接口层
                    if ($this->system == 'TIPY'){
                        $child['code'] = strtolower('DAML_APIM_'.$child['value']);
                    }

                    $config[] = $child;
                }
            }
        }
        $configs = [];
        // 生成ApiDoc配置信息
        foreach ($config as $key=>$value){
            $configs[$key]['title']  = $value['label'];
            $configs[$key]['folder'] = $value['code'];
            $configs[$key]['path']   = "app\\" . $value['code'] . "\\controller";
            foreach ($value['version'] as $k=>$v){
                $configs[$key]['items'][$k]             = [];
                $configs[$key]['items'][$k]['title']    = $v;
                $configs[$key]['items'][$k]['path']     = "app\\" . $value['code'] . "\\controller\\".$v;
                $configs[$key]['items'][$k]['folder']   = $v;
                $configs[$key]['items'][$k]['groups']   = [
                    ['title'=>'前端','name'=>'qianduan','name'=>'qianduan'],
                    ['title'=>'后端','name'=>'houduan','name'=>'houduan'],
                ];

            }
        }
        return $configs;
    }

    /**
     * @title                           获取当前系统接口版本
     * @description                     获取当前系统接口版本
     * @author                          袁波
     * @adddate                         2021/9/28
     * @lasteditTime                    2021/9/28
     */
    public function getSystemVersion(){
        try {
            $data     = [];
            $field    = ['version'];
            //查询所有code返回码和消息
            $version     = Db::table('daml_apim_api_version')->where('status', '=', 0)->field($field)->select()->toArray();
            if ($version){
                foreach ($version as $k=>$v){
                    $data[] = $v['version'];
                }
            }
        }
        catch(\think\exception\ErrorException $e){
            throw new \think\Exception($e->getMessage());
        }
        return $data;
    }

    /**
     * @title                           栏目无限分类
     * @description                     栏目无限分类
     * @author                          袁波
     * @adddate                         2021/9/28
     * @lasteditTime                    2021/9/28
     * @param       String   $data      栏目列表
     * @param       String   $pid       父id
     * @param       String   $level     层级
     */
    public function tree_cate($data, $pid = 0, $level = 0)
    {
        $child = [];   // 定义存储子级数据数组
        foreach ($data as $key => $value) {
            if ($value['pid'] == $pid) {
                unset($data[$key]);  // 使用过后可以销毁
                $value['level'] = $level + 1;
                $value['children'] = $this->tree_cate($data, $value['id'], $value['level']);   // 递归调用，查找当前数据的子级
                $value['children'] = count($value['children']) > 0 ? $value['children'] : false;
                $child[] =  $value;   // 把子级数据添加进数组
            }
        }
        return $child;
    }

}