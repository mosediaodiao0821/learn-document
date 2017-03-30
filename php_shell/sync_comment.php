<?php

@ini_set("display_errors",1);
date_default_timezone_set('America/Los_Angeles');
set_time_limit(0);
$option = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
define('PAGE_SIZE', 100);
define('INTERVAL_TIME', 900);
$dsn = 'mysql:host=%s;dbname=%s;charset=%s';

$curTime = date('Y-m-d H:i:s',time());
// cadealmoon
$db_ca = new PDO(sprintf($dsn, 'localhost', 'cadealmoon', 'latin1'), 'dm', 'bookface06', $option);
// ugc
$db_ugc = new PDO(sprintf($dsn, 'localhost', 'ugc', 'utf8'), 'dm', 'bookface06', $option);

$oldMaxId = $db_ca->query("SELECT MAX(id) FROM deal_comments")->fetchColumn();

$newMaxId = $db_ugc->query("SELECT MAX(id) FROM deal_comments_archive")->fetchColumn();



//处理新添加的评论
if ($newMaxId > $oldMaxId) {

    $comments = getCommentsById($oldMaxId, $newMaxId);
    echo "add:".count($comments) . "\n";
    insertData($comments,'replace');

}
//处理新修改的评论
$up_comments = getCommentsByTime();

echo "update:". count($up_comments) . "\n";

if(!empty($up_comments)){
    insertData($up_comments,'replace');
}

file_put_contents(__DIR__.'/comment_update_time.txt',$curTime);

function getCommentsById($min, $max) {
    global $db_ugc;
    $sql = "SELECT * FROM deal_comments_archive WHERE id > ". $min ." AND id <= " .$max ." LIMIT " . PAGE_SIZE;
    #echo $sql . "\n";
    return $db_ugc->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getCommentsByTime(){
    global $db_ugc;
    global $curTime;
    echo "curTime:" . $curTime. "\n";
    //读取评论已处理的最大id
    $commentTime = file_get_contents(__DIR__.'/comment_update_time.txt');
    if(empty($commentTime)){
        echo "commentTime:".$commentTime . "\n";
        return array();
    }else{
        $sql = "SELECT * FROM deal_comments_archive WHERE update_time > '" .$commentTime."' AND update_time <= '" .$curTime ."' and update_time > submit_time" ;
        echo $sql . "\n";
        return $db_ugc->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

function insertData($comments, $mode){
    global $db_ca;
    foreach ($comments as $comment) {

        $sql = autoSql( 'deal_comments', array(
            'id'                            => $comment['id'],
            'deal_id'                       => $comment['res_id'],
            'message'                      => $comment['message'],
            'state'                         => $comment['state'],
            'submit_time'                  => $comment['submit_time'],
            'user_name'                    => $comment['user_name'],
            'udid'                          => $comment['udid'],
            'ip'                            => $comment['ip'],
            'browser'                       => $comment['browser'],
            'is_chinese'                   => $comment['is_chinese'],
            'avatar_url'                    => $comment['avatar_url'],
            'last_editor'                   => $comment['last_editor'],
        ), $mode);

        $db_ca->query($sql);

        echo "replace {$comment['id']}\n";
    }
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

