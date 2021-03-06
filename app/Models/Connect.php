<?php

namespace App\Models;

use Phalcon\Mvc\Model\Behavior\SoftDelete;

class Connect extends Model
{

    const PROVIDER_QQ = 1; // QQ
    const PROVIDER_WEIXIN = 2; // 微信
    const PROVIDER_WEIBO = 3; // 微博

    /**
     * 主键编号
     *
     * @var int
     */
    public $id;

    /**
     * 用户编号
     *
     * @var int
     */
    public $user_id;

    /**
     * 开放ID
     *
     * @var string
     */
    public $open_id;

    /**
     * 开放名称
     *
     * @var string
     */
    public $open_name;

    /**
     * 开放头像
     *
     * @var string
     */
    public $open_avatar;

    /**
     * 提供商
     *
     * @var int
     */
    public $provider;

    /**
     * 删除标识
     *
     * @var int
     */
    public $deleted;

    /**
     * 创建时间
     *
     * @var int
     */
    public $create_time;

    /**
     * 更新时间
     *
     * @var int
     */
    public $update_time;

    public function getSource(): string
    {
        return 'kg_connect';
    }

    public function initialize()
    {
        parent::initialize();

        $this->addBehavior(
            new SoftDelete([
                'field' => 'deleted',
                'value' => 1,
            ])
        );
    }

    public function beforeCreate()
    {
        $this->create_time = time();
    }

    public function beforeUpdate()
    {
        $this->update_time = time();
    }

}
