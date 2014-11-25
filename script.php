<?php

/**
 * @author Alexander Nikonov <i@alexnick.ru>
 * @version 0.1a
 * @license  "THE BEER-WARE LICENSE":
 * Can do whatever you want with this stuff. 
 * If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer.
 */
//@ini_set('error_reporting', E_ALL ^ E_WARNING ^ E_NOTICE);

class VKGroupStats
{

    protected $id = -1;
    static $apiURL = 'https://api.vk.com/method/';
    protected $methods = array(
        'getPosts' => 'wall.get',
        'getLikes' => 'likes.getList',
        'getUser' => 'users.get',
        'getReposts' => 'wall.getReposts',
        'getComments' => 'wall.getComments'
    );
    protected $mysql;
    protected $url;
    protected $showLog = TRUE;

    //protected $data;

    /**
     * 
     * @param int $id
     * @param int $type
     * @return boolean
     * @throws \Exception
     */
    public function __construct($id = -1, $type = 0)
    {
        if (!is_int($id)) {
            throw new \Exception('Invalid parameter passed to id!');
        }
        if ($type === 0) {
            $this->id = $id * -1;
        } elseif ($type === 1) {
            $this->id = $id;
        }
        $dsn = "mysql:host=localhost;dbname=vk;charset=utf8";
        $opt = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        );
        $this->mysql = new \PDO($dsn, 'vk', 'vk', $opt);
        $this->mysql->exec("SET NAMES 'utf8';");
        return true;
    }

    /**
     * 
     * @param array $array
     * @param string $method
     * @return boolean
     * @throws \Exception
     */
    private function buildUrl(array $array, $method = 'getPosts')
    {
        if (!isset($this->methods[$method])) {
            throw new \Exception('Unknown method');
        }
        if (is_array($array)) {
            if (count($array) === 0) {
                throw new \Exception('The array can not be empty');
            }
        } else {
            throw new \Exception('Requires an array of data');
        }

        $this->url = self::$apiURL . $this->methods[$method] . '?' . http_build_query($array);
        return true;
    }

    /**
     * 
     * @return boolean
     */
    private function _getPosts()
    {
        $countPost = 100;
        for ($index = 0; $index <= $countPost / 100; $index++) {
            $offset = 100 * $index;
            $this->buildUrl(array(
                'owner_id' => $this->id,
                'offset' => $offset,
                'count' => 100
                    ), 'getPosts');
            $data = json_decode(file_get_contents($this->url));
            $array = array();
            if ($this->showLog) {
                echo "Load posts. Offset: {$offset}" . PHP_EOL;
            }
            for ($i = 1; $i < count($data->response); $i++) {
                $arr = $data->response[$i];
                $userId = intval($arr->signer_id);
                $array[] = array(
                    'post_id' => $arr->id,
                    'date' => $arr->date,
                    'post_type' => $arr->post_type,
                    'signer_id' => $userId,
                    'comments' => intval($arr->comments->count),
                    'likes' => intval($arr->likes->count),
                    'reposts' => intval($arr->reposts->count),
                );
            }
            foreach ($array as $key => $value) {
                $value = (object) $value;
                $userId = intval($value->signer_id);
                $this->checkUser($userId);

                $stm = $this->mysql->prepare('SELECT post_id FROM `posts` WHERE `post_id`=' . $value->post_id);
                $stm->execute();
                if ($stm->fetchColumn() != FALSE) {
                    break;
                }
                $this->mysql->exec("INSERT INTO  `posts` (
                                        `id` ,`post_id` ,`date` ,`post_type` ,`signer_id` ,`comments` ,`likes` ,`reposts`
                                    ) VALUES (
                                        NULL ,  '$value->post_id',  '$value->date', '$value->post_type',  
                                        '$userId',  '$value->comments',  '$value->likes',  '$value->reposts'
                                    );");
                $this->mysql->exec("UPDATE  `users` SET "
                        . " `posts` = posts+1, `got_likes` = got_likes+$value->likes, `got_reposts` = got_reposts+$value->reposts  "
                        . " WHERE  `user_id`=$userId;");
                if ($this->showLog) {
                    echo "Add post $value->post_id DONE!" . PHP_EOL;
                }
            }
            if ($index === 0) {
                $countPost = $data->response[0];
            }
        }
        return true;
    }

    /**
     * 
     * @return boolean
     */
    private function getPostLikes()
    {
        $stm = $this->mysql->prepare('SELECT * FROM posts ORDER BY id ASC');
        $stm->execute();
        $data = $stm->fetchAll();

        for ($i = 0; $i < count($data); $i++) {
            $dataLikes = array();
            $likeInfo = $data[$i];

            $this->buildUrl(array(
                'type' => 'post',
                'owner_id' => $this->id,
                'item_id' => $likeInfo['post_id'],
                'extended' => 1,
                'offset' => 0,
                'count' => 1000
                    ), 'getLikes');
            $dataLikes[$likeInfo['post_id']][] = json_decode(file_get_contents($this->url));
            if ($likeInfo['likes'] > 1000) {
                for ($index = 1000; $index < $likeInfo['likes']; $index = $index + 1000) {
                    $this->buildUrl(array(
                        'type' => 'post',
                        'owner_id' => $this->id,
                        'item_id' => $likeInfo['post_id'],
                        'extended' => 1,
                        'offset' => $index,
                        'count' => 1000
                            ), 'getLikes');
                    $dataLikes[$likeInfo['post_id']][] = json_decode(file_get_contents($this->url));
                }
            }
            foreach ($dataLikes as $key => $value) {
                foreach ($value as $kt => $vt) {
                    foreach ($vt->response->items as $k => $v) {
                        $this->checkUser($v->uid);
                        $this->mysql->exec("UPDATE  `users` SET "
                                . "`likes` = likes+1"
                                . " WHERE  `user_id`=$v->uid;");
                        $this->mysql->exec("INSERT INTO  `posts_like` (
                                        `id`,`user_id` ,`post_id`
                                    ) VALUES (
                                        NULL ,  '$v->uid',  '$key'
                                    );");
                        if ($this->showLog) {
                            echo "Add post $key like user $v->uid DONE!" . PHP_EOL;
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * 
     * @param int $id
     * @return boolean
     */
    private function checkUser($id = 0)
    {
        if ($id == 0) {
            return true;
        }
        $id = intval($id);
        $stm = $this->mysql->prepare('SELECT * FROM `users` WHERE `user_id`=' . $id);
        $stm->execute();
        if ($stm->fetchColumn() === FALSE && $stm->fetchColumn() !== 0) {
            $this->buildUrl(array(
                'user_ids' => $id,
                'fields' => $this->id,
                    ), 'getUser');
            $data = json_decode(file_get_contents($this->url));
            $userName = $data->response[0]->first_name . ' ' . $data->response[0]->last_name;
            $STH = $this->mysql->prepare("INSERT INTO  `users` (
                                        `user_id` ,`name`
                                    ) VALUES (
                                        $id ,  ?
                                    );");
            $STH->execute(array($userName));
            if ($this->showLog) {
                echo "Add user $id DONE!" . PHP_EOL;
            }
        }
        return true;
    }

    /**
     * 
     * @return boolean
     */
    private function getPostReposts()
    {
        $stm = $this->mysql->prepare('SELECT * FROM posts WHERE reposts != 0 ORDER BY id ASC');
        $stm->execute();
        $data = $stm->fetchAll();
        for ($i = 0; $i < count($data); $i++) {
            $dataReposts = array();
            $repostInfo = $data[$i];
            $this->buildUrl(array(
                'type' => 'post',
                'owner_id' => $this->id,
                'item_id' => $repostInfo['post_id'],
                'filter' => 'copies',
                'extended' => 0,
                'offset' => 0,
                'count' => 1000
                    ), 'getLikes');
            $dataReposts[$repostInfo['post_id']][] = json_decode(file_get_contents($this->url));
            if ($repostInfo['reposts'] > 1000) {
                for ($index = 1000; $index < $repostInfo['reposts']; $index = $index + 1000) {
                    $this->buildUrl(array(
                        'type' => 'post',
                        'owner_id' => $this->id,
                        'item_id' => $repostInfo['post_id'],
                        'filter' => 'copies',
                        'extended' => 0,
                        'offset' => $index,
                        'count' => 1000
                            ), 'getLikes');
                    $dataReposts[$repostInfo['post_id']][] = json_decode(file_get_contents($this->url));
                }
            }
            foreach ($dataReposts as $key => $value) {
                foreach ($value as $kt => $vt) {
                    foreach ($vt->response->users as $k => $v) {
                        if ($v < 0) {
                            continue;
                        }
                        $this->checkUser($v);
                        $this->mysql->exec("UPDATE  `users` SET "
                                . "`reposts` = reposts+1"
                                . " WHERE  `user_id`=$v;");
                        $this->mysql->exec("INSERT INTO  `posts_repost` (
                          `id`,`user_id` ,`post_id`
                          ) VALUES (
                          NULL ,  '$v',  '$key'
                          );");
                        if ($this->showLog) {
                            echo "Add post $key repost user $v DONE!" . PHP_EOL;
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * 
     * @return boolean
     */
    private function getPostComments()
    {
        $stm = $this->mysql->prepare('SELECT * FROM posts WHERE comments != 0 AND post_id < 73047 ORDER BY id ASC');
        $stm->execute();
        $data = $stm->fetchAll();
        for ($i = 0; $i < count($data); $i++) {
            $dataComments = array();
            $commentInfo = $data[$i];
            $this->buildUrl(array(
                'owner_id' => $this->id,
                'post_id' => $commentInfo['post_id'],
                'need_likes' => 1,
                'sort' => 'DESC',
                'preview_length' => 1,
                'offset' => 0,
                'count' => 100
                    ), 'getComments');
            $dataComments[$commentInfo['post_id']][] = json_decode(file_get_contents($this->url));
            if ($commentInfo['comments'] > 100) {
                for ($index = 100; $index < $commentInfo['comments']; $index = $index + 100) {
                    $this->buildUrl(array(
                        'owner_id' => $this->id,
                        'post_id' => $commentInfo['post_id'],
                        'need_likes' => 1,
                        'sort' => 'DESC',
                        'preview_length' => 1,
                        'offset' => 0,
                        'count' => 100
                            ), 'getComments');
                    $dataComments[$commentInfo['post_id']][] = json_decode(file_get_contents($this->url));
                }
            }
            foreach ($dataComments as $key => $value) {
                foreach ($value as $kt => $vt) {
                    foreach ($vt->response as $keyComment => $valueComment) {
                        if ($keyComment == 0) {
                            continue;
                        }
                        $stm = $this->mysql->prepare('SELECT * FROM `posts_comments` WHERE `comment_id`=' . $valueComment->cid);
                        $stm->execute();
                        if ($stm->fetchColumn() !== FALSE) {
                            continue;
                        }
                        $this->checkUser($valueComment->uid);
                        $this->mysql->exec("UPDATE  `users` SET "
                                . "`comments` = comments+1, "
                                . "`got_likes_comments` = got_likes_comments+{$valueComment->likes->count}"
                                . " WHERE  `user_id`=$valueComment->uid;");
                        $this->mysql->exec("INSERT INTO  `posts_comments` (
                          `comment_id`,`user_id` ,`post_id`, `date`, `likes`
                          ) VALUES (
                          '$valueComment->cid' ,  '$valueComment->uid',  '$key', '$valueComment->date', '{$valueComment->likes->count}'
                          );");
                        if ($this->showLog) {
                            echo "Add comment $valueComment->cid to post $key for user $valueComment->uid DONE!" . PHP_EOL;
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * 
     * @return boolean
     */
    public function exec()
    {
        if ($this->showLog) {
            echo "Parse posts start...." . PHP_EOL;
        }
        $this->_getPosts();
        if ($this->showLog) {
            echo "Parse posts DONE...." . PHP_EOL;
            sleep(10);
            echo "Parse user info start...." . PHP_EOL;
        }
        //$this->infoUsersPosts();
        //if ($this->showLog) {
        //    echo "Parse user info DONE...." . PHP_EOL;
        //    sleep(10);
        //    echo "Parse post likes start...." . PHP_EOL;
        //}
        $this->getPostLikes();
        if ($this->showLog) {
            echo "Parse post likes DONE...." . PHP_EOL;
            sleep(10);
            echo "Parse post reposts start...." . PHP_EOL;
        }
        $this->getPostReposts();
        if ($this->showLog) {
            echo "Parse post reposts DONE...." . PHP_EOL;
            sleep(10);
            echo "Parse post comments start...." . PHP_EOL;
        }
        $this->getPostComments();
        if ($this->showLog) {
            echo "Parse post comments DONE...." . PHP_EOL;
        }
        return true;
    }

    public function showUsers()
    {
//        $stm = $this->mysql->prepare('SELECT  `comments` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`comments` DESC 
//                                    LIMIT 0 , 10');
//        $stm = $this->mysql->prepare('SELECT  `likes` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`likes` DESC 
//                                    LIMIT 0 , 10');
//         $stm = $this->mysql->prepare('SELECT  `reposts` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`reposts` DESC 
//                                    LIMIT 0 , 10');
//        $stm = $this->mysql->prepare('SELECT  `posts` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`posts` DESC 
//                                    LIMIT 0 , 10');
//        $stm = $this->mysql->prepare('SELECT  `got_likes` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`got_likes` DESC 
//                                    LIMIT 0 , 10');
//        $stm = $this->mysql->prepare('SELECT  `got_likes_comments` AS data,  `name` ,  `user_id` 
//                                    FROM  `users` 
//                                    ORDER BY  `users`.`got_likes_comments` DESC 
//                                    LIMIT 0 , 10');
        $stm = $this->mysql->prepare('SELECT  `got_reposts` AS data,  `name` ,  `user_id` 
                                    FROM  `users` 
                                    ORDER BY  `users`.`got_reposts` DESC 
                                    LIMIT 0 , 10');
        $stm->execute();
        $data = $stm->fetchAll();
        foreach ($data as $key => $value) {
            echo '@id' . $value['user_id'] . ' (' . $value['name'] . ')     ' . $value['data'] . '<br/>' . PHP_EOL;
        }
    }

}

$App = new VKGroupStats(1, 0);
//$App->exec();
$App->showUsers();
