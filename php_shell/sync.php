<?php

@ini_set("display_errors",1);
date_default_timezone_set('America/Los_Angeles');
set_time_limit(0);
$option = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
define('PAGE_SIZE', 100);
define('INTERVAL_TIME', 900);
$dsn = 'mysql:host=%s;dbname=%s;charset=%s';

$curTime = time();
// cadealmoon
$db_ca = new PDO(sprintf($dsn, 'localhost', 'cadealmoon', 'latin1'), 'dm', 'bookface06', $option);
// dealmoon
$db_dm = new PDO(sprintf($dsn, 'localhost', 'dealmoon', 'utf8'), 'dm', 'bookface06', $option);
// ugc
$db_ugc = new PDO(sprintf($dsn, 'localhost', 'ugc', 'utf8'), 'dm', 'bookface06', $option);

$caMaxId = $db_ca->query("SELECT MAX(deal_id) FROM cn_deals")->fetchColumn();

$dmMaxId = $db_dm->query("SELECT MAX(id) FROM deal_index")->fetchColumn();


//处理新添加的deal
if ($dmMaxId > $caMaxId) {

    $deals = getDealsById($caMaxId, $dmMaxId);
    echo count($deals) . "\n";
    insertData($deals,'replace');

}
//处理新修改的deal
$up_deals = getDealsByTime();
$latest_deals = getDealsLatest();
echo count($up_deals) . "\n";
$up_deals = array_merge($up_deals,$latest_deals);

echo count($up_deals) . "\n";
if(!empty($up_deals)){
    insertData($up_deals,'update');
}
//读取评论已处理的最大id
/*$commentMaxId = file_get_contents(__DIR__.'/comment_max_id.txt');

//获取需要处理的评论
$comments = getComments($commentMaxId);
if(!empty($comments)){
    #$commentNum = 0;
    foreach($comments as $comment){
        if($comment['id'] > $commentMaxId){
            $commentMaxId = $comment['id'];
            #echo $commentMaxId . "\n";
        }
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
        insertCommentData($comment, 'deal_comments_archive'); # 写入归档数据
        if (preg_match('/(new|normal|info|bad)/i', $comment['state'])) {
            insertCommentData($comment, 'deal_comments'); # 写入展示数据
            $commentNum += 1;
        }
        $db_dm->query('update deal_index set comment_num = comment_num + 1 WHERE id = '.$comment['deal_id']);
        echo "commentId:{$comment['id']},dealId:{$comment['deal_id']}\n";
    }

    //commentMaxId写入文件
    file_put_contents(__DIR__.'/comment_max_id.txt',$commentMaxId);
}else{
    echo "comment is empty\n";
}*/


function getComments($maxId){
    global $db_ca;
    $sql = "SELECT * FROM deal_comments WHERE id > " . $maxId ." LIMIT " . PAGE_SIZE;
    #echo $sql . "\n";
    return $db_ca->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

# 写入Comment数据
function insertCommentData($comment, $tableName) {
    global $db_ugc;
    $sql = autoSql($tableName, array(

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
    ), 'insert');

    $db_ugc->query($sql);

}

function getDealsById($min, $max) {
    global $db_dm;
    $sql = "SELECT * FROM deal_index i LEFT JOIN deal_data d ON i.id = d.deal_id WHERE i.id > ". $min ." AND i.id <= " .$max ." LIMIT " . PAGE_SIZE;
    #echo $sql . "\n";
    return $db_dm->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getDealsByTime(){
    global $db_dm;
    global $curTime;
    echo "curTime:" . date('Y-m-d H:i:s',$curTime) . "\n";
    $mintime = date('Y-m-d H:i:s',$curTime - INTERVAL_TIME);
    $maxtime = date('Y-m-d H:i:s',$curTime);
    echo "time:$mintime\n";
    $sql = "SELECT * FROM deal_index i LEFT JOIN deal_data d ON i.id = d.deal_id WHERE d.update_time > '" .$mintime."' AND d.update_time <= '" .$maxtime ."' AND d.update_time > i.create_time " ;
    #echo $sql . "\n";
    return $db_dm->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function getDealsLatest(){
    global $db_dm;

    $sql = "SELECT * FROM deal_index i LEFT JOIN deal_data d ON i.id = d.deal_id WHERE i.cn_state = 'published'order by i.published_time DESC limit 20" ;

    return $db_dm->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}


function autoSql($table, $field_values, $mode = 'INSERT', $where = '') {
    $mode = strtoupper($mode);

    $sql = '';
    if (in_array($mode, array('INSERT', 'REPLACE'))) {
        $fields = $values = array();
        foreach ($field_values AS $key => $value) {
            $fields[] = "`" . $key . "`";

            if($key =='price' || $key == 'expiration_time'){
                if($value == -1){
                    $values[] = 'null';
                }else{
                    $values[] = "'" . $value . "'";
                }
            }else{
                $values[] = $value === 'null' ? "''" : "'" . addslashes($value) . "'";
            }

        }

        if (!empty($fields)) {
            $sql = $mode . ' INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
            #echo $sql . "\n";
        }
    }

    if ($mode == 'UPDATE') {
        $sets = array();
        foreach ($field_values AS $key => $value) {
            if($key =='price' || $key == 'expiration_time'){
                if($value == -1){
                    $sets[] = "`" . $key . "`" . " = null";
                    #echo "`" . $key . "`" . " = " . $value . "\n";
                }else{
                    $sets[] = "`" . $key . "`" . " = '" . $value . "'";
                    #echo "`" . $key . "`" . " = '" . $value . "'\n";
                }

            }else{
                $sets[] = "`" . $key . "`" . " = '" . addslashes($value) . "'";
            }

        }
        #echo $where . "\n";
        if (!empty($sets)) {
            $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        }
        #echo $sql . "\n";
    }

    return $sql;
}
function insertData($deals, $mode){
    global $db_ca;
    foreach ($deals as $deal) {
        empty($deal['price']) && $deal['price'] = -1;
        $expiration_time = -1;

        if(!empty($deal['expiration_time'])){
            $expiration_time = date('Y-m-d H:i:s',$deal['expiration_time']);

        }

        $enDealSql = autoSql( 'hotdeals', array(
            'id'                        => $deal['id'],
            'url'                       => $deal['url'],
            'title'                     => $deal['en_title'],
            'title_ex'                 => $deal['en_title_ex'],
            'body'                      => $deal['cn_body'],
            'source'                    => $deal['source'],
            'published_date'           => date('Y-m-d H:i:s',$deal['published_time']),
            'total_click'               => $deal['click_num'],
            'total_view'                => $deal['view_num'],
            'store_name'                => $deal['store_name'],
            'image_url'                 => $deal['en_image_url'],
            'is_sticky'                 => $deal['is_sticky'],
            'price'                     => $deal['price'],
            'price_dropped'            => $deal['price_dropped'],
            'perc_dropped'             => $deal['perc_dropped'],
            'category'                  => $deal['category'],
            'store_id'                  => $deal['store_id'],
            'recipient'                 => $deal['recipient'],
            'state'                     => $deal['cn_state'],
            'flags'                     => $deal['flag'],
            'is_hotpick'                => $deal['is_hotpick'],
            'is_exclusive'              => $deal['is_exclusive'],
            'tip_weibo'                 => $deal['tip_weibo'],
            'created'                   => $deal['created'],
            'modified'                  => $deal['modified'],
            'link_state'                => $deal['link_state'],
            'expiration_time'          => $expiration_time,
            'en_banner_url'             => $deal['en_banner_url'],
            'mid'                        => $deal['mid'],
            'sp_group_id'               => 0,
            'sp_default_view'          => $deal['sp_default_view'],
        ), $mode, 'id = '.$deal['id']);

        $cnDealSql = autoSql( 'cn_deals', array(
            'deal_id'                        => $deal['id'],
            'title_cn'                       => $deal['cn_title'],
            'body_cn'                        => $deal['cn_body'],
            'title_ex_cn'                   => $deal['cn_title_ex'],
            'display_order'                 => $deal['display_order'],
            'published_date'                => date('Y-m-d H:i:s',$deal['published_time']),
            'state'                         => $deal['cn_state'],
            'image_url_cn'                  => $deal['cn_image_url'],
            'is_sticky'                     => $deal['is_sticky'],
            'cn_banner_url'                => $deal['cn_banner_url'],
        ), $mode, 'deal_id = '.$deal['id']);

        $db_ca->query($enDealSql);
        $db_ca->errorInfo();
        $db_ca->query($cnDealSql);
        $db_ca->errorInfo();
        $db_ca->query("delete from deal_category where deal_id = {$deal['id']}");
        if($deal['cn_state'] == 'published'){
            $cat = array_filter(array_unique(explode(',', $deal['category'])));
            if(!empty($cat)){
                foreach ($cat as &$value) {
                    $catSql = autoSql( 'deal_category', array(
                        'deal_id'                        => $deal['id'],
                        'category_id'                   => $value,
                        'is_hotbuy'                     => 0,
                        'lang'                           => 'cn',
                        'is_hotpick'                    => $deal['is_hotpick'],
                        'published_date'                => date('Y-m-d H:i:s',$deal['published_time']),
                    ), "replace");
                    $db_ca->query($catSql);
                }
            }

        }
        echo "replace {$deal['id']}\n";
    }
}
