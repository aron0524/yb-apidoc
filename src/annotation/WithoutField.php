<?php

namespace yuanbo\apidoc\annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * 排除模型的字段
 * @package yuanbo\apidoc\annotation
 * @Annotation
 * @Target({"METHOD"})
 */
class WithoutField extends Annotation
{}
