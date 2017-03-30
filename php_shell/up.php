<?php

/**
 * 运行脚本
 * 解析脚本设置 -c 参数为 [名:值@名:值,...] 如:php ./u.php -c=limit:10@minDealId:2@dealIds:1,2,3@...
 */

/*
数据处理逻辑
1. 准备相关数据库 同线上数据库即可
    dealmoon : 
        deal_index
        deal_data

    ugc :
        local_deal_comments_archive
        deal_comments_archive
        deal_comments
        deal_comment_like
        deal_comment_image
 
2. 处理基本准备数据
    评论相关
    INSERT INTO local_deal_comments_archive (SELECT * from local_deal_comments);


 */

class U {
    const PAGE_SIZE = 3000; # 每次转换条数
    const DSN = 'mysql:dbname=%s;host=%s;port=%s'; # 实例化数据库字串

    var $act        = ''; # [CMD]默认的操作
    var $limit      = 0; # [CMD]转化的数据量
    var $minDealId  = 0; # [CMD]转化最小的DealID
    var $maxDealId  = 0; # [CMD]转化最大的DealId
    var $dealIds    = ''; # [CMD]所需转换的DealId 中间可以用,分割开
    var $hotDealNum = 0; # 需要转化的条数
    var $db_r       = array(); # 读取数据库
    var $db_w       = array(); # 写入数据库
    var $deal_ids   = array(); # 需要处理的数据
    var $cmd        = array(); # 通过命令执行时的参数获取
    var $categories = array(); #分类数据

    # 初始化数据库 
    public function initDb() {
    	# 实例化DB 加拿大站只有dealmoon库
    	$this->db_r = array(
			'dealmoon'   =>  $this->getPdo('dealmoon', 'localhost', '3306', 'dm', 'bookface06'),
    	);

    	$this->db_w = array(
    		'dealmoon'   =>  $this->getPdo('dealmoon', 'localhost', '3306', 'dm', 'bookface06'),
            'ugc'        =>  $this->getPdo('ugc', 'localhost', '3306', 'dm', 'bookface06'),
    	);
    }

    public function __construct() {
        $this->initCMD(); # 初始化CMD获取的数据

        $this->act       = $this->getParameter('act');
        $this->limit     = $this->getParameter('limit');
        $this->minDealId = $this->getParameter('minDealId');
        $this->maxDealId = $this->getParameter('maxDealId');
        $this->dealIds   = $this->getParameter('dealIds');
    }

    # 初始化命令行传入的参数
    public function initCMD() {
        $cmd = explode('@', reset(getOpt("c:")));
        
        foreach($cmd as $v) {
           $tmp = explode(':', $v);
           $this->cmd[$tmp[0]] = $tmp[1];
        }
    }

    /**
     * 获取参数
     * @param  [type] $str [description]
     * @return [type]      [description]
     */
    public function getParameter($str) {
        return isset($this->cmd[$str]) ? $this->cmd[$str] : $_REQUEST[$str];
    }

    public function main() {
       	$this->init();

       	$this->doIt();
    }

    # 初始化
    public function init() {
        $this->checkEnvironment();
    	$this->initEnvironment();
    	$this->initDb();
    	$this->checkInitDb();
        $this->prepareData();
    }

    # 验证运行时是否满足需要
    public function checkEnvironment() {
        if(!class_exists('PDO'))  {
            throw new Exception('未安装PDO环境,请联系运维安装.');
        }
    }

    # 初始化环境
    public function initEnvironment() {
        defined('E_DEPRECATED')
            ? error_reporting(E_ALL & ~E_STRICT & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED)
            : error_reporting(E_ALL & ~E_STRICT & ~E_WARNING & ~E_NOTICE);

    	date_default_timezone_set('America/Los_Angeles'); # 设置时区
        set_time_limit(0); # 延长脚本运行时间
        ini_set('memory_limit','2048M'); # 设定内存大小
    }

    public function getPdo($dbname, $host, $port, $user, $pwd) {
        $ret = new PDO(sprintf(self::DSN, $dbname, $host, $port), $user, $pwd);
        $ret->exec("SET wait_timeout=28800"); # 延长数据库连接时间 TODO
        $ret->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $ret;
    }

    # 验证数据库是否初始化成功 TODO
    public function checkInitDb() {
        // SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbname'
    }

    # 准备好deal需要处理的数据
    public function prepareData() {
        // $sql = 'SELECT hd.id FROM hotdeals hd JOIN cn_deals cd ON hd.id=cd.deal_id';
        $sql = 'SELECT deal_id FROM cn_deals WHERE 1 ';
        if(!empty($this->dealIds)) {
            $sql .= " AND deal_id IN ({$this->dealIds}) ";
        }
        if(!empty($this->minDealId)) {
            $sql .= " AND deal_id >= {$this->minDealId}";
        }
        if(!empty($this->maxDealId)) {
            $sql .= " AND deal_id <= {$this->maxDealId}";
        }
        if($this->limit) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        $this->deal_ids = array_unique(array_filter($this->getAll($this->db_r['dealmoon'], $sql, PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE, 0)));

        $this->categories = $this->db_w['dealmoon']->query('SELECT * FROM category')->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    }

    # 请求分发
    public function doIt() {
    	switch ($this->act) {
    		case 'do':

    			$this->doChange();
    			break;

    		case 'show':
    		default:
    			$this->showDetail();
    			break;
    	}
    }

    # 查看脚本的基本情况
    public function showDetail() {
        echo '<pre>';
        echo "需要处理" . count($this->deal_ids) . "条数据:\n";
    }

    # 执行数据转换
    public function doChange() {
        $chunk = array_chunk($this->deal_ids, self::PAGE_SIZE);
        while ($deals = array_pop($chunk)) {
            $hotdeals = $this->getAll($this->db_r['dealmoon'], 'SELECT * FROM hotdeals WHERE id IN(' . implode(',', $deals) . ')');
            foreach ($hotdeals as $dealInfo) {
                if(empty($dealInfo['id'])) {
                    $this->error("endeal:{$dealInfo['id']} not find");
                    continue;
                }
                $cnDeal = reset($this->getAll($this->db_r['dealmoon'], "SELECT * FROM cn_deals WHERE deal_id = {$dealInfo['id']}"));
                if (empty($cnDeal)) {
                    $this->error("cndeal:{$dealInfo['id']} not find");
                    continue; # 只转换CN的Deal
                }

                //获取评论
                $comments = $this->getAll($this->db_r['dealmoon'], "SELECT * FROM deal_comments WHERE deal_id = {$dealInfo['id']}");

                $this->insertData($dealInfo, $cnDeal, $comments); # 写入数据

                echo "deal:[{$dealInfo['id']}]处理完毕!\n";
            }
        }
    }

    # 数据内容处理
    public function dataAddslashes($ret) {
        if(!is_array($ret)) {
            return array();
        }
        foreach ($ret as $key => $value) {
            if (is_null($value)) {
                continue;
            }
            $ret[$key] = addslashes($value);
        }

        return $ret;
    }

    # 写入数据
    public function insertData($enDeal, $cnDeal, $comments) {
        $flag2 = '';
        $enDeal['flag'] = explode(',', $enDeal['flags']);
        $allow = array('dead','need_mod','no_expire','trash');
        foreach ($enDeal['flag'] as &$value) {
            if (!in_array($value, $allow)) {
                unset($value);
                continue;
            }
            if ($value == 'dead') {
                $flag2 = 'dead';
            } elseif ($value == 'no_expire') {
                $flag2 || $flag2 = 'no_expire';
            }

        }
        $enDeal['flag'] = implode(',', $enDeal['flag']);

        empty($enDeal['cn_state']) && $enDeal['cn_state'] = $cnDeal['state'];
        //$is_show_en     = $enDeal['state'] == 'published' ? 1 : 0;
        $is_show_cn     = $enDeal['cn_state'] == 'published' ? 1 : 0;
        
        empty($enDeal['update_time']) && $enDeal['update_time'] = $enDeal['published_date'];
        #$enDeal['published_date'] = strtotime($enDeal['published_date']);
        $enDeal['price'] === null && $enDeal['price'] = 'null';
        empty($enDeal['sp_default_view']) && $enDeal['sp_default_view'] = 'less';
        
        $cat = array_filter(array_unique(explode(',', $enDeal['category'])));
        if(!empty($cat)){
            foreach ($cat as &$value) {
                if (!isset($this->categories[$value])) {
                    echo "category:{$value} not find \n";
                    unset($value);
                    continue;
                }
                $category = $this->categories[$value];

                if (strpos($category['path'], '|') !== false) {
                    if ($category['parent_id']) {
                        $this->insertDealCategory('deal_category_1',$enDeal,$is_show_cn,$flag2,$category['parent_id']);
                    }
                    $this->insertDealCategory('deal_category_2',$enDeal,$is_show_cn,$flag2,$value);

                } else {
                    $this->insertDealCategory('deal_category_1',$enDeal,$is_show_cn,$flag2,$value);
                }
            }
        }
        

        # $favorite_num = (int) $this->getOne($this->db_r['dm_ucenter'], "SELECT COUNT(*) FROM favorite WHERE deal_id = {$enDeal['id']} AND fav_type = 'deal'");
        # $comment_num  = (int) $this->getOne($this->db_r['ugc'], "SELECT COUNT(*) FROM deal_comments WHERE res_id = {$enDeal['id']}");
        # $share_num    = (int) $this->getOne($this->db_r['ugc'], "SELECT COUNT(*) FROM share_record WHERE res_data = {$enDeal['id']} AND type = 'deal'");

        #$favorite_num = $share_num = 0; # 加拿大站没有这些数据

        $this->autoExecute($this->db_w['dealmoon'], 'deal_index', array(
            'id'               => $enDeal['id'],
            'category'         => implode(',', $cat),
            'en_state'         => 'hidden',
            'cn_state'         => $enDeal['cn_state'],
            'is_show_haitao'   => 0,
            'flag'             => $enDeal['flag'],
            'is_hotpick'       => $enDeal['is_hotpick'],
            'show_by_location' => 'all',
            'store_id'         => $enDeal['store_id'],
            'recipient'        => $enDeal['recipient'],
            'is_exclusive'     => $enDeal['is_exclusive'],
            'display_order'    => $cnDeal['display_order'],
            'is_sticky'        => $cnDeal['is_sticky'],
            'click_num'        => $enDeal['total_click'],
            'view_num'         => $enDeal['total_view'],
            'favorite_num'     => 0,
            'comment_num'      => 0,
            'share_num'        => 0,
            'created'          => $enDeal['created'],
            'modified'         => $enDeal['modified'],
            'link_state'       => $enDeal['link_state'],
            'expiration_time'  => strtotime($enDeal['expiration_time']),
            'published_time'   => strtotime($enDeal['published_date']),
            'first_published_time'  => strtotime($enDeal['published_date'])
        ), 'replace');
        

        $this->autoExecute($this->db_w['dealmoon'], 'deal_data', array(
            'deal_id'          => $enDeal['id'],
            'url'              => $enDeal['url'],
            'en_title'         => $enDeal['title'],
            'cn_title'         => $cnDeal['title_cn'],
            'en_title_ex'      => $enDeal['title_ex'],
            'cn_title_ex'      => $cnDeal['title_ex_cn'],
            'en_body'          => $enDeal['body'],
            'cn_body'          => $cnDeal['body_cn'],
            'en_image_url'     => $enDeal['image_url'],
            'cn_image_url'     => $cnDeal['image_url_cn'],
            'en_banner_url'    => $enDeal['en_banner_url'],
            'cn_banner_url'    => $cnDeal['cn_banner_url'],
            'mid'              => $enDeal['mid'],
            'price'            => $enDeal['price'],
            'price_dropped'    => $enDeal['price_dropped'],
            'perc_dropped'     => $enDeal['perc_dropped'],
            'tip_weibo'        => $enDeal['tip_weibo'],
            'mark_weibo'       => $enDeal['mark_weibo'],
            'source'           => $enDeal['source'],
            'store_name'       => $enDeal['store_name'],
            'sp_group_id'      => $enDeal['sp_group_id'],
            'sp_default_view'  => $enDeal['sp_default_view'],
            'comment_disabled' => $enDeal['comment_disabled'],
            'haitao_country'   => '',
            'haitao_payment'   => '',
            'haitao_shipping'  => '',
            'haitao_price'     => 0.00,
            'china_price'      => 0.00,
            'direct_delivery'  => '',
            'haitao_tip'       => '',
            'update_time'      => $enDeal['update_time']
        ), 'replace');


        if(!empty($comments)){
            $commentNum = 0;
            foreach($comments as $comment){

                # $parentId = reset($this->getAll($this->db_r['dealmoon'], "SELECT parent_comment_id FROM deal_comments_relation WHERE comment_id = {$comment['id']} AND parent_comment_id != 0"));
                #if(empty($parentId)){
                #    $parentId = 0;
                #}
                empty($comment['state']) && $comment['state'] = 'normal';
                $comment['type'] = 'mobile';
                if(strstr($comment['browser'],"iPhone")){
                    if(empty($comment['udid'])){
                        $comment['source'] = 'wap';
                        $comment['user_name'] = '手机匿名';

                    }else{
                        $comment['source'] = 'iphone';
                        $comment['user_name'] = 'iPhone客户端';
                    }
                }elseif(strstr($comment['browser'],"Android")){
                    if(empty($comment['udid'])){
                        $comment['source'] = 'wap';
                        $comment['user_name'] = '手机匿名';
                    }else{
                        $comment['source'] = 'android';
                        $comment['user_name'] = 'android客户端';
                    }
                }elseif(strstr($comment['browser'],"iPad")){
                    if(empty($comment['udid'])){
                        $comment['source'] = 'wap';
                        $comment['user_name'] = '手机匿名';
                    }else{
                        $comment['source'] = 'ipad';
                        $comment['user_name'] = 'iPad客户端';
                    }
                }else{
                    $comment['source'] = 'pc';
                    $comment['user_name'] = '网页匿名';
                    $comment['type'] = 'web';
                }
                $this->insertCommentData($comment, 'deal_comments_archive'); # 写入归档数据
                if (preg_match('/(new|normal|info|bad)/i', $comment['state'])) {
                    $this->insertCommentData($comment, 'deal_comments'); # 写入展示数据
                    $commentNum += 1;
                }
                echo "commentId:{$comment['id']},dealId:{$comment['deal_id']}\n";
            }
            //更新deal评论数
            if($commentNum != 0){
                $this->autoExecute($this->db_w['dealmoon'],'deal_index',array(
                    'comment_num'      => $commentNum
                ),'update',"id={$enDeal['id']}");
                echo "comment_num:{$commentNum}\n";
            }
        }else{
            echo "comment is empty\n";
        }

    }

    public function insertDealCategory($tableName, $enDeal, $is_show_cn, $flag, $categoryId){
        $this->db_w['dealmoon']->query('delete from ' . $tableName . ' where deal_id = ' . $enDeal['id'] . ' and category_id = ' . $categoryId);
        $this->autoExecute($this->db_w['dealmoon'], $tableName, array(
            'deal_id'                   => $enDeal['id'],
            'is_show_en'               => 0,
            'is_show_cn'               => $is_show_cn,
            'is_show_haitao'          => 0,
            'flag'                     => $flag,
            'is_hotpick'              => $enDeal['is_hotpick'],
            'show_by_location'       => 'all',
            'category_id'             => $categoryId,
            'published_time'         => strtotime($enDeal['published_date'])
        ), 'insert');
    }
    # 写入Comment数据
    public function insertCommentData($comment, $tableName) {

        $this->autoExecute($this->db_w['ugc'], $tableName, array(
            'id'                    => $comment['id'],
            'res_id'                => $comment['deal_id'],
            'message'                => $comment['message'],
            'state'                 => $comment['state'],
            'submit_time'           => $comment['submit_time'],
            'uid'                   => $comment['uid'],
            'user_name'             => $comment['user_name'],
            'udid'                    => $comment['udid'],
            'ip'                      => $comment['ip'],
            'browser'               => $comment['browser'],
            'is_chinese'             => $comment['is_chinese'],
            'avatar_url'            => $comment['avatar_url'],
            'last_editor'            => $comment['last_editor'],
            'type'                   => $comment['type'],
            'is_top'                => $comment['is_top'],
            'source'                => $comment['source'],
            'like_num'              => 0,
            'parent_id'              => 0,
            'intercept_reason'      => '',
            'country'               => '',
            'city'                  => '',
        ), 'replace');

    }
    # 通过ip获取国家城市
    function getLocationInfoByIp($ip){
        if(empty($ip)){
           return '';
        }
        $res = @file_get_contents('http://int.dpool.sina.com.cn/iplookup/iplookup.php?format=js&ip=' . $ip);
        if(empty($res)){ return false; }
        $jsonMatches = array();
        preg_match('#\{.+?\}#', $res, $jsonMatches);
        if(!isset($jsonMatches[0])){ return false; }
        $json = json_decode($jsonMatches[0], true);
        if(isset($json['ret']) && $json['ret'] == 1){
            $json['ip'] = $ip;
            unset($json['ret']);
        }else{
            return false;
        }
        return $json;
    }
    # 异常信息的记录
    public function error($msg) {
        echo $msg . "\n";
    }

    /**
     * 获取数据库中的数据
     * @param  [type] $db_pdo [description]
     * @param  string $sql    [description]
     * @param  [type] $type   [description]
     * @return [type]         [description]
     */
    public function getAll($db_pdo, $sql='', $type = PDO::FETCH_ASSOC, $fetch_argument=null) {
        if(!($db_pdo instanceof PDO)  || empty($sql)) {
            return false;
        }

        $ret = $db_pdo->query($sql);
        if($ret instanceof PDOStatement) {
            $ret = $fetch_argument !== null ? $ret->fetchAll($type, $fetch_argument) : $ret->fetchAll($type);
        }

        return $ret;
    }

    /**
     * 获取数据库返回的一个值
     * @param  [type] $db_pdo [description]
     * @param  string $sql    [description]
     * @param  [type] $type   [description]
     * @return [type]         [description]
     */
    public function getOne($db_pdo, $sql='', $type = PDO::FETCH_ASSOC) {
        if(!($db_pdo instanceof PDO)  || empty($sql)) {
            return false;
        }

        $ret = $db_pdo->query($sql);

        return $ret ? $ret->fetchColumn() : $ret;
    }

    /**
     * [autoExecute description]
     * @param  [type] $db           [description]
     * @param  [type] $table        [description]
     * @param  [type] $field_values [description]
     * @param  string $mode         [description]
     * @param  string $where        [description]
     * 
     * @return [type]               [description]
     */
    public function autoExecute($db, $table, $field_values, $mode = 'INSERT', $where = '') {
        $sql = $this->autoSql($table, $field_values, $mode, $where);

        $run = $db->query($sql); 
        if(!$run) {
            $this->error('异常SQL' . $sql);
            exit;
        }

        return $run;
    }

    /**
     * 获取组装后的SQL
     * @param  [type] $table        需要处理的数据库
     * @param  [type] $field_values 需要更新的数据 key为列名,value为值
     * @param  string $mode         模式: 支持insert,replace,update
     * @param  string $where        where 条件
     * 
     * @return [type]               返回sql
     */
    public function autoSql($table, $field_values, $mode = 'INSERT', $where = '') {
        $mode = strtoupper($mode);

        $sql = '';
        if (in_array($mode, array('INSERT', 'REPLACE'))) {
            $fields = $values = array();
            foreach ($field_values AS $key => $value) {
                $fields[] = "`" . $key . "`";
                if($key =='price'){
                    $values[] = $value;
                }else{
                    $values[] = $value === 'null' ? "''" : "'" . addslashes($value) . "'";
                }

            }

            if (!empty($fields)) {
                $sql = $mode . ' INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
            }
        } 

        if ($mode == 'UPDATE') {
            $sets = array();
            foreach ($field_values AS $key => $value) {
                $sets[] = "`" . $key . "`" . " = '" . $value . "'";
            }

            if (!empty($sets)) {
                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
            }
        }

        return $sql;
    }
}
$startTime = date('Y-m-d H:i:s',time());
echo "START : ",$startTime,"\n";
$u = new U();
$u->main();
$endTime = date('Y-m-d H:i:s',time());
echo "END : ",$endTime,"\n";
