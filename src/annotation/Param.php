<?php

namespace yuanbo\apidoc\annotation;


/**
 * 请求参数
 * @package yuanbo\apidoc\annotation
 * @Annotation
 * @Target({"METHOD","ANNOTATION"})
 */
final class Param extends ParamBase
{


    /**
     * 必须
     * @var bool
     */
    public $require = false;
    
    /**
     * 引入
     * @var string
     */
    public $ref;
}
