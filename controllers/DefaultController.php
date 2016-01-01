<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\controllers;

use bizley\podium\components\Cache;
use bizley\podium\components\Config;
use bizley\podium\components\Helper;
use bizley\podium\log\Log;
use bizley\podium\models\Category;
use bizley\podium\models\Forum;
use bizley\podium\models\Message;
use bizley\podium\models\Post;
use bizley\podium\models\PostThumb;
use bizley\podium\models\SearchForm;
use bizley\podium\models\Subscription;
use bizley\podium\models\Thread;
use bizley\podium\models\ThreadView;
use bizley\podium\models\User;
use bizley\podium\models\Vocabulary;
use bizley\podium\rbac\Rbac;
use Exception;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\StringHelper;
use yii\helpers\Url;
use Zelenin\yii\extensions\Rss\RssView;

/**
 * Podium Default controller
 * All actions concerning viewing and moderating forums and posts.
 * 
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */
class DefaultController extends BaseController
{

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'access' => [
                    'class' => AccessControl::className(),
                    'rules' => [
                        [
                            'allow'         => false,
                            'matchCallback' => function ($rule, $action) {
                                return !$this->module->getInstalled();
                            },
                            'denyCallback' => function ($rule, $action) {
                                return $this->redirect(['install/run']);
                            }
                        ],
                        [
                            'allow' => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Checking the thread of given category ID, forum ID, own ID and slug.
     * @param integer $category_id
     * @param integer $forum_id
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */  
    protected function _verifyThread($category_id = null, $forum_id = null, $id = null, $slug = null)
    {
        if (!is_numeric($category_id) || $category_id < 1 || !is_numeric($forum_id) || $forum_id < 1 || !is_numeric($id) || $id < 1 || empty($slug)) {
            return false;
        }

        $conditions = ['id' => (int)$category_id];
        if (Yii::$app->user->isGuest) {
            $conditions['visible'] = 1;
        }
        $category = Category::find()->where($conditions)->limit(1)->one();

        if (!$category) {
            return false;
        }
        else {
            $conditions = ['id' => (int) $forum_id, 'category_id' => $category->id];
            if (Yii::$app->user->isGuest) {
                $conditions['visible'] = 1;
            }
            $forum = Forum::find()->where($conditions)->limit(1)->one();
            
            if (!$forum) {
                return false;
            }
            else {
                $thread = Thread::find()->where(['id' => (int) $id, 'category_id' => $category->id, 'forum_id' => $forum->id, 'slug' => $slug])->limit(1)->one();
                
                if (!$thread) {
                    return false;
                }
                else {
                    return [$category, $forum, $thread];
                }
            }
        }
    }
    
    /**
     * Showing ban info.
     */
    public function actionBan()
    {
        $this->layout = 'maintenance';
        return $this->render('ban');
    }
    
    /**
     * Displaying the category of given ID and slug.
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionCategory($id = null, $slug = null)
    {
        if (!is_numeric($id) || $id < 1 || empty($slug)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the category you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $conditions = ['id' => (int)$id, 'slug' => $slug];
        if (Yii::$app->user->isGuest) {
            $conditions['visible'] = 1;
        }
        $model = Category::find()->where($conditions)->limit(1)->one();

        if (!$model) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the category you are looking for.'));
            return $this->redirect(['default/index']);
        }
        
        $this->setMetaTags($model->keywords, $model->description);

        return $this->render('category', ['model' => $model]);
    }
    
    /**
     * Deleting the thread of given category ID, forum ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionDelete($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list($category, $forum, $thread) = $verify;
        
        if (User::can(Rbac::PERM_DELETE_THREAD, ['item' => $thread])) {

            $postData = Yii::$app->request->post();
            if ($postData) {
                $delete = $postData['thread'];
                if (is_numeric($delete) && $delete > 0 && $delete == $thread->id) {
                    
                    $transaction = Thread::getDb()->beginTransaction();
                    try {
                        if ($thread->delete()) {
                            $forum->updateCounters(['threads' => -1, 'posts' => -$thread->posts]);
                            $transaction->commit();

                            Cache::getInstance()->delete('forum.threadscount');
                            Cache::getInstance()->delete('forum.postscount');
                            Cache::getInstance()->delete('user.threadscount');
                            Cache::getInstance()->delete('user.postscount');

                            Log::info('Thread deleted', $thread->id, __METHOD__);
                            $this->success(Yii::t('podium/flash', 'Thread has been deleted.'));
                            return $this->redirect(['forum', 'cid' => $forum->category_id, 'id' => $forum->id, 'slug' => $forum->slug]);
                        }
                    }
                    catch (Exception $e) {
                        $transaction->rollBack();
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while deleting the thread.'));
                    }
                }
                else {
                    $this->error(Yii::t('podium/flash', 'Incorrect thread ID.'));
                }
            }
            
            return $this->render('delete', [
                'category' => $category,
                'forum'    => $forum,
                'thread'   => $thread,
            ]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to delete the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }
    
    /**
     * Deleting the post of given category ID, forum ID, thread ID and ID.
     * @param integer $cid
     * @param integer $fid
     * @param integer $tid
     * @param integer $pid
     * @return string|\yii\web\Response
     */
    public function actionDeletepost($cid = null, $fid = null, $tid = null, $pid = null)
    {
        if (!is_numeric($cid) || $cid < 1 || !is_numeric($fid) || $fid < 1 || !is_numeric($tid) || $tid < 1 || !is_numeric($pid) || $pid < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $category = Category::findOne((int)$cid);

        if (!$category) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }
        else {
            $forum = Forum::find()->where(['id' => (int)$fid, 'category_id' => $category->id])->limit(1)->one();

            if (!$forum) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                return $this->redirect(['default/index']);
            }
            else {
                $thread = Thread::find()->where(['id' => (int)$tid, 'category_id' => $category->id, 'forum_id' => $forum->id])->limit(1)->one();

                if (!$thread) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                    return $this->redirect(['default/index']);
                }
                else {
                    if ($thread->locked == 0 || ($thread->locked == 1 && User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $thread]))) {
                        $model = Post::find()->where(['id' => (int)$pid, 'forum_id' => $forum->id, 'thread_id' => $thread->id])->limit(1)->one();
                        
                        if (!$model) {
                            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                            return $this->redirect(['default/index']);
                        }
                        else {
                            if (User::can(Rbac::PERM_DELETE_OWN_POST, ['post' => $model]) || User::can(Rbac::PERM_DELETE_POST, ['item' => $model])) {

                                $postData = Yii::$app->request->post();
                                if ($postData) {
                                    $delete = $postData['post'];
                                    if (is_numeric($delete) && $delete > 0 && $delete == $model->id) {

                                        $transaction = Post::getDb()->beginTransaction();
                                        try {
                                            if ($model->delete()) {
                                                
                                                $wholeThread = false;
                                                if ((new Query)->from(Post::tableName())->where(['thread_id' => $thread->id, 'forum_id' => $forum->id])->count()) {
                                                    $thread->updateCounters(['posts' => -1]);
                                                    $forum->updateCounters(['posts' => -1]);
                                                }
                                                else {
                                                    $wholeThread = true;
                                                    $thread->delete();
                                                    $forum->updateCounters(['posts' => -1, 'threads' => -1]);
                                                }
                                                
                                                $transaction->commit();

                                                Cache::getInstance()->delete('forum.threadscount');
                                                Cache::getInstance()->delete('forum.postscount');
                                                Cache::getInstance()->delete('user.postscount');

                                                Log::info('Post deleted', !empty($model->id) ? $model->id : '', __METHOD__);
                                                $this->success(Yii::t('podium/flash', 'Post has been deleted.'));
                                                if ($wholeThread) {
                                                    return $this->redirect(['forum', 'cid' => $forum->category_id, 'id' => $forum->id, 'slug' => $forum->slug]);
                                                }
                                                else {
                                                    return $this->redirect(['thread', 'cid' => $thread->category_id, 'fid' => $thread->forum_id, 'id' => $thread->id, 'slug' => $thread->slug]);
                                                }
                                            }
                                        }
                                        catch (Exception $e) {
                                            $transaction->rollBack();
                                            Log::error($e->getMessage(), null, __METHOD__);
                                            $this->error(Yii::t('podium/flash', 'Sorry! There was an error while deleting the post.'));
                                        }
                                    }
                                    else {
                                        $this->error(Yii::t('podium/flash', 'Incorrect thread ID.'));
                                    }
                                }

                                return $this->render('deletepost', [
                                            'model'       => $model,
                                            'category'    => $category,
                                            'forum'       => $forum,
                                            'thread'      => $thread,
                                ]);
                            }
                            else {
                                if (Yii::$app->user->isGuest) {
                                    $this->warning(Yii::t('podium/flash', 'Please sign in to delete the post.'));
                                    return $this->redirect(['account/login']);
                                }
                                else {
                                    $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                                    return $this->redirect(['default/index']);
                                }
                            }
                        }
                    }
                    else {
                        $this->info(Yii::t('podium/flash', 'This thread is locked.'));
                        return $this->redirect(['thread', 'cid' => $category->id, 'fid' => $forum->id, 'thread' => $thread->id, 'slug' => $thread->slug]);
                    }
                }
            }
        }
    }
    
    /**
     * Deleting the posts of given category ID, forum ID, thread ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionDeleteposts($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list($category, $forum, $thread) = $verify;
        
        if (User::can(Rbac::PERM_DELETE_POST, ['item' => $thread])) {

            if (Yii::$app->request->post()) {
                
                $posts = Yii::$app->request->post('post');
                
                if (empty($posts) || !is_array($posts)) {
                    $this->error(Yii::t('podium/flash', 'You have to select at least one post.'));
                }
                else {
                    $transaction = Post::getDb()->beginTransaction();
                    try {
                        $error = false;
                        foreach ($posts as $post) {
                            if (!is_numeric($post) || $post < 1) {
                                $this->error(Yii::t('podium/flash', 'Incorrect post ID.'));
                                $error = true;
                                break;
                            }
                            else {
                                $nPost = Post::findOne(['id' => $post, 'thread_id' => $thread->id, 'forum_id' => $forum->id]);
                                if (!$nPost) {
                                    $this->error(Yii::t('podium/flash', 'We can not find the post with this ID.'));
                                    $error = true;
                                    break;
                                }
                                else {
                                    $nPost->delete();
                                }
                            }
                        }
                        if (!$error) {
                            
                            $wholeThread = false;
                            if ((new Query)->from(Post::tableName())->where(['thread_id' => $thread->id, 'forum_id' => $forum->id])->count()) {
                                $thread->updateCounters(['posts' => -count($posts)]);
                                $forum->updateCounters(['posts' => -count($posts)]);
                            }
                            else {
                                $wholeThread = true;
                                $thread->delete();
                                $forum->updateCounters(['posts' => -count($posts), 'threads' => -1]);
                            }
                            
                            $transaction->commit();

                            Cache::getInstance()->delete('forum.threadscount');
                            Cache::getInstance()->delete('forum.postscount');
                            Cache::getInstance()->delete('user.threadscount');
                            Cache::getInstance()->delete('user.postscount');

                            Log::info('Posts deleted', null, __METHOD__);
                            $this->success(Yii::t('podium/flash', 'Posts have been deleted.'));
                            if ($wholeThread) {
                                return $this->redirect(['forum', 'cid' => $forum->category_id, 'id' => $forum->id, 'slug' => $forum->slug]);
                            }
                            else {
                                return $this->redirect(['thread', 'cid' => $thread->category_id, 'fid' => $thread->forum_id, 'id' => $thread->id, 'slug' => $thread->slug]);
                            }
                        }
                    }
                    catch (Exception $e) {
                        $transaction->rollBack();
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while deleting the posts.'));
                    }
                }
            }
            
            return $this->render('deleteposts', [
                'category'     => $category,
                'forum'        => $forum,
                'thread'       => $thread,
                'dataProvider' => (new Post)->search($forum->id, $thread->id)
            ]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to update the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }
    
    /**
     * Editing the post of given category ID, forum ID, thread ID and own ID.
     * If this is the first post in thread user can change the thread name.
     * @param integer $cid
     * @param integer $fid
     * @param integer $tid
     * @param integer $pid
     * @return string|\yii\web\Response
     */
    public function actionEdit($cid = null, $fid = null, $tid = null, $pid = null)
    {
        if (!is_numeric($cid) || $cid < 1 || !is_numeric($fid) || $fid < 1 || !is_numeric($tid) || $tid < 1 || !is_numeric($pid) || $pid < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $category = Category::findOne((int)$cid);

        if (!$category) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }
        else {
            $forum = Forum::find()->where(['id' => (int)$fid, 'category_id' => $category->id])->limit(1)->one();

            if (!$forum) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                return $this->redirect(['default/index']);
            }
            else {
                $thread = Thread::find()->where(['id' => (int)$tid, 'category_id' => $category->id, 'forum_id' => $forum->id])->limit(1)->one();

                if (!$thread) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                    return $this->redirect(['default/index']);
                }
                else {
                    if ($thread->locked == 0 || ($thread->locked == 1 && User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $thread]))) {
                        $model = Post::find()->where(['id' => (int)$pid, 'thread_id' => $thread->id, 'forum_id' => $forum->id])->limit(1)->one();

                        if (!$model) {
                            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                            return $this->redirect(['default/index']);
                        }
                        else {
                            if (User::can(Rbac::PERM_UPDATE_OWN_POST, ['post' => $model]) || User::can(Rbac::PERM_UPDATE_POST, ['item' => $model])) {
                                $isFirstPost = false;
                                $firstPost   = Post::find()->where(['thread_id' => $thread->id, 'forum_id' => $forum->id])->orderBy(['id' => SORT_ASC])->limit(1)->one();
                                if ($firstPost->id == $model->id) {
                                    $model->setScenario('firstPost');
                                    $model->topic = $thread->name;
                                    $isFirstPost = true;
                                }                            

                                $postData = Yii::$app->request->post();
                                $preview  = '';

                                if ($model->load($postData)) {
                                    if ($model->validate()) {
                                        if (isset($postData['preview-button'])) {
                                            $preview = $model->content;
                                        }
                                        else {
                                            $transaction = Post::getDb()->beginTransaction();
                                            try {
                                                $model->edited    = 1;
                                                $model->edited_at = time();

                                                if ($model->save()) {
                                                    if ($isFirstPost) {
                                                        $thread->name = $model->topic;
                                                        $thread->save();
                                                    }
                                                    $model->markSeen();
                                                    $thread->touch('edited_post_at');
                                                }

                                                $transaction->commit();

                                                Log::info('Post updated', $model->id, __METHOD__);
                                                $this->success(Yii::t('podium/flash', 'Post has been updated.'));

                                                return $this->redirect(['show', 'id' => $model->id]);
                                            }
                                            catch (Exception $e) {
                                                $transaction->rollBack();
                                                Log::error($e->getMessage(), null, __METHOD__);
                                                $this->error(Yii::t('podium/flash', 'Sorry! There was an error while adding the reply. Contact administrator about this problem.'));
                                            }
                                        }
                                    }
                                }

                                return $this->render('edit', [
                                            'preview'     => $preview,
                                            'model'       => $model,
                                            'category'    => $category,
                                            'forum'       => $forum,
                                            'thread'      => $thread,
                                            'isFirstPost' => $isFirstPost
                                ]);
                            }
                            else {
                                if (Yii::$app->user->isGuest) {
                                    $this->warning(Yii::t('podium/flash', 'Please sign in to edit the post.'));
                                    return $this->redirect(['account/login']);
                                }
                                else {
                                    $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                                    return $this->redirect(['default/index']);
                                }
                            }
                        }
                    }
                    else {
                        $this->info(Yii::t('podium/flash', 'This thread is locked.'));
                        return $this->redirect(['thread', 'cid' => $category->id, 'fid' => $forum->id, 'id' => $thread->id, 'slug' => $thread->slug]);
                    }
                }
            }
        }
    }

    /**
     * Displaying the forum of given category ID, own ID and slug.
     * @param integer $cid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionForum($cid = null, $id = null, $slug = null, $toggle = null)
    {
        if (!is_numeric($cid) || $cid < 1 || !is_numeric($id) || $id < 1 || empty($slug)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $conditions = ['id' => (int)$cid];
        if (Yii::$app->user->isGuest) {
            $conditions['visible'] = 1;
        }
        $category = Category::find()->where($conditions)->limit(1)->one();

        if (!$category) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $conditions = ['id' => (int)$id, 'category_id' => $category->id, 'slug' => $slug];
        if (Yii::$app->user->isGuest) {
            $conditions['visible'] = 1;
        }
        
        $filters = Yii::$app->session->get('forum-filters');
        if (in_array(strtolower($toggle), ['new', 'edit', 'hot', 'pin', 'lock', 'all'])) {
            if (strtolower($toggle) == 'all') {
                $filters = null;
            }
            else {
                $filters[strtolower($toggle)] = empty($filters[strtolower($toggle)]) || $filters[strtolower($toggle)] == 0 ? 1 : 0;
            }
            Yii::$app->session->set('forum-filters', $filters);
        }
        
        $model = Forum::find()->where($conditions)->limit(1)->one();
        
        if (!$model) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
            return $this->redirect(['default/index']);
        }

        $keywords = $model->keywords;
        if (!$keywords) {
            $keywords = $category->keywords;
        }
        $description = $model->description;
        if (!$description) {
            $description = $category->description;
        }
        $this->setMetaTags($keywords, $description);
        
        return $this->render('forum', [
                    'model'    => $model,
                    'category' => $category,
                    'filters'  => $filters
        ]);
    }
    
    /**
     * Displaying the list of categories.
     * @return string
     */
    public function actionIndex()
    {
        $this->setMetaTags();
        
        return $this->render('index', ['dataProvider' => (new Category)->search()]);
    }
    
    /**
     * Direct link for the last post in thread of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionLast($id = null)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }
        
        $thread = Thread::findOne((int)$id);
        if (!$thread) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }
        
        $url = [
            'default/thread', 
            'cid'  => $thread->category_id,
            'fid'  => $thread->forum_id, 
            'id'   => $thread->id, 
            'slug' => $thread->slug
        ];

        try {
            $count = (new Query)->from(Post::tableName())->where(['thread_id' => $thread->id])->orderBy(['id' => SORT_ASC])->count();
            $page = floor($count / 10) + 1;

            if ($page > 1) {
                $url['page'] = $page;
            }

            return $this->redirect($url);
        }
        catch (Exception $e) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }
    }
    
    /**
     * Locking the thread of given category ID, forum ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return \yii\web\Response
     */
    public function actionLock($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list(,, $thread) = $verify;
        
        if (User::can(Rbac::PERM_LOCK_THREAD, ['item' => $thread])) {
            if ($thread->locked) {
                $thread->locked = 0;
            }
            else {
                $thread->locked = 1;
            }
            if ($thread->save()) {
                if ($thread->locked) {
                    Log::info('Thread locked', $thread->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Thread has been locked.'));
                }
                else {
                    Log::info('Thread unlocked', $thread->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Thread has been unlocked.'));
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! There was an error while updating the thread.'));
            }
            return $this->redirect(['default/thread', 'cid' => $cid, 'fid' => $fid, 'id' => $id, 'slug' => $slug]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to update the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }
    
    /**
     * Showing maintenance info.
     */
    public function actionMaintenance()
    {
        $this->layout = 'maintenance';
        return $this->render('maintenance');
    }
    
    /**
     * Moving the thread of given category ID, forum ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionMove($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list($category, $forum, $thread) = $verify;
        
        if (User::can(Rbac::PERM_MOVE_THREAD, ['item' => $thread])) {
            $postData = Yii::$app->request->post();
            if ($postData) {
                $moveTo = $postData['forum'];
                if (is_numeric($moveTo) && $moveTo > 0 && $moveTo != $forum->id) {
                    $newParent = Forum::findOne($moveTo);
                    if ($newParent) {
                        $postsCount = $thread->posts;
                        $oldParent = Forum::findOne($thread->forum_id);
                        if ($oldParent) {
                            $transaction = Forum::getDb()->beginTransaction();
                            try {
                                $oldParent->updateCounters(['threads' => -1, 'posts' => -$postsCount]);
                                $newParent->updateCounters(['threads' => 1, 'posts' => $postsCount]);
                                
                                $thread->forum_id    = $newParent->id;
                                $thread->category_id = $newParent->category_id;
                                if ($thread->save()) {
                                    Post::updateAll(['forum_id' => $newParent->id], 'thread_id = :tid', [':tid' => $thread->id]);
                                }
                                
                                $transaction->commit();
                                
                                Log::info('Thread moved', $thread->id, __METHOD__);
                                $this->success(Yii::t('podium/flash', 'Thread has been moved.'));
                                return $this->redirect(['thread', 'cid' => $thread->category_id, 'fid' => $thread->forum_id, 'id' => $thread->id, 'slug' => $thread->slug]);
                            }
                            catch (Exception $e) {
                                $transaction->rollBack();
                                Log::error($e->getMessage(), null, __METHOD__);
                                $this->error(Yii::t('podium/flash', 'Sorry! There was an error while moving the thread.'));
                            }
                        }
                        else {
                            $this->error(Yii::t('podium/flash', 'Sorry! There was an error while moving the thread.'));
                        }
                    }
                    else {
                        $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you want to move this thread to.'));
                    }
                }
                else {
                    $this->error(Yii::t('podium/flash', 'Incorrect forum ID.'));
                }
            }
            
            $categories = Category::find()->orderBy(['name' => SORT_ASC])->all();
            $forums     = Forum::find()->orderBy(['name' => SORT_ASC])->all();
            
            $list    = [];
            $options = [];
            foreach ($categories as $cat) {
                $catlist = [];
                foreach ($forums as $for) {
                    if ($for->category_id == $cat->id) {
                        $catlist[$for->id] = (User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $for]) ? '* ' : '') . Html::encode($cat->name) . ' &raquo; ' . Html::encode($for->name);
                        if ($for->id == $forum->id) {
                            $options[$for->id] = ['disabled' => true];
                        }
                    }
                }
                $list[Html::encode($cat->name)] = $catlist;
            }
            
            return $this->render('move', [
                'category' => $category,
                'forum'    => $forum,
                'thread'   => $thread,
                'list'     => $list,
                'options'  => $options
            ]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to update the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }
    
    /**
     * Moving the posts of given category ID, forum ID, thread ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionMoveposts($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list($category, $forum, $thread) = $verify;
        
        if (User::can(Rbac::PERM_MOVE_POST, ['item' => $thread])) {

            if (Yii::$app->request->post()) {
                
                $posts     = Yii::$app->request->post('post');
                $newthread = Yii::$app->request->post('newthread');
                $newname   = Yii::$app->request->post('newname');
                $newforum  = Yii::$app->request->post('newforum');
                
                if (empty($posts) || !is_array($posts)) {
                    $this->error(Yii::t('podium/flash', 'You have to select at least one post.'));
                }
                else {
                    if (!is_numeric($newthread) || $newthread < 0) {
                        $this->error(Yii::t('podium/flash', 'You have to select a thread for this posts to be moved to.'));
                    }
                    else {
                        if ($newthread == 0 && (empty($newname) || empty($newforum) || !is_numeric($newforum) || $newforum < 1)) {
                            $this->error(Yii::t('podium/flash', 'If you want to move posts to a new thread you have to enter its name and select parent forum.'));
                        }
                        else {
                            if ($newthread == $thread->id) {
                                $this->error(Yii::t('podium/flash', 'Are you trying to move posts from this thread to this very same thread?'));
                            }
                            else {
                                $transaction = Thread::getDb()->beginTransaction();
                                try {
                                    if ($newthread == 0) {
                                        $parent = Forum::findOne($newforum);
                                        if (!$parent) {
                                            $this->error(Yii::t('podium/flash', 'We can not find the parent forum with this ID.'));
                                        }
                                        else {
                                            $nThread = new Thread;
                                            $nThread->name        = $newname;
                                            $nThread->posts       = 0;
                                            $nThread->views       = 0;
                                            $nThread->category_id = $parent->category_id;
                                            $nThread->forum_id    = $parent->id;
                                            $nThread->author_id   = User::loggedId();
                                            $nThread->save();
                                        }
                                    }
                                    else {
                                        $nThread = Thread::findOne($newthread);
                                        if (!$nThread) {
                                            $this->error(Yii::t('podium/flash', 'We can not find the thread with this ID.'));
                                        }
                                    }
                                    if (!empty($nThread)) {
                                        $error = false;
                                        foreach ($posts as $post) {
                                            if (!is_numeric($post) || $post < 1) {
                                                $this->error(Yii::t('podium/flash', 'Incorrect post ID.'));
                                                $error = true;
                                                break;
                                            }
                                            else {
                                                $nPost = Post::find()->where(['id' => $post, 'thread_id' => $thread->id, 'forum_id' => $forum->id])->limit(1)->one();
                                                if (!$nPost) {
                                                    $this->error(Yii::t('podium/flash', 'We can not find the post with this ID.'));
                                                    $error = true;
                                                    break;
                                                }
                                                else {
                                                    $nPost->thread_id = $nThread->id;
                                                    $nPost->forum_id  = $nThread->forum_id;
                                                    $nPost->save();
                                                }
                                            }
                                        }
                                        if (!$error) {
                                            $wholeThread = false;
                                            if ((new Query)->from(Post::tableName())->where(['thread_id' => $thread->id, 'forum_id' => $forum->id])->count()) {
                                                $thread->updateCounters(['posts' => -count($posts)]);
                                                $forum->updateCounters(['posts' => -count($posts)]);
                                            }
                                            else {
                                                $wholeThread = true;
                                                $thread->delete();
                                                $forum->updateCounters(['posts' => -count($posts), 'threads' => -1]);
                                            }
                                            
                                            $nThread->updateCounters(['posts' => count($posts)]);
                                            $nThread->forum->updateCounters(['posts' => count($posts)]);
                                            
                                            $transaction->commit();
                                            
                                            Cache::getInstance()->delete('forum.threadscount');
                                            Cache::getInstance()->delete('forum.postscount');
                                            Cache::getInstance()->delete('user.postscount');
                                            Cache::getInstance()->delete('forum.latestposts');

                                            Log::info('Posts moved', null, __METHOD__);
                                            $this->success(Yii::t('podium/flash', 'Posts have been moved.'));
                                            if ($wholeThread) {
                                                return $this->redirect(['default/forum', 'cid' => $forum->category_id, 'id' => $forum->id, 'slug' => $forum->slug]);
                                            }
                                            else {
                                                return $this->redirect(['default/thread', 'cid' => $thread->category_id, 'fid' => $thread->forum_id, 'id' => $thread->id, 'slug' => $thread->slug]);
                                            }
                                        }
                                    }
                                }
                                catch (Exception $e) {
                                    $transaction->rollBack();
                                    Log::error($e->getMessage(), null, __METHOD__);
                                    $this->error(Yii::t('podium/flash', 'Sorry! There was an error while moving the posts.'));
                                }
                            }
                        }
                    }
                }
            }
            
            $categories = Category::find()->orderBy(['name' => SORT_ASC])->all();
            $forums     = Forum::find()->orderBy(['name' => SORT_ASC])->all();
            $threads    = Thread::find()->orderBy(['name' => SORT_ASC])->all();
            
            $list      = [0 => Yii::t('podium/view', 'Create new thread')];
            $listforum = [];
            $options   = [];
            foreach ($categories as $cat) {
                $catlist = [];
                foreach ($forums as $for) {
                    $forlist = [];
                    if ($for->category_id == $cat->id) {
                        $catlist[$for->id] = (User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $for]) ? '* ' : '') . Html::encode($cat->name) . ' &raquo; ' . Html::encode($for->name);
                        foreach ($threads as $thr) {
                            if ($thr->category_id == $cat->id && $thr->forum_id == $for->id) {
                                $forlist[$thr->id] = (User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $thr]) ? '* ' : '') . Html::encode($cat->name) . ' &raquo; ' . Html::encode($for->name) . ' &raquo; ' . Html::encode($thr->name);
                                if ($thr->id == $thread->id) {
                                    $options[$thr->id] = ['disabled' => true];
                                }
                            }
                        }
                        $list[Html::encode($cat->name) . ' > ' . Html::encode($for->name)] = $forlist;
                    }
                }
                $listforum[Html::encode($cat->name)] = $catlist;
            }
            
            return $this->render('moveposts', [
                'category'     => $category,
                'forum'        => $forum,
                'thread'       => $thread,
                'list'         => $list,
                'options'      => $options,
                'listforum'    => $listforum,
                'dataProvider' => (new Post)->search($forum->id, $thread->id)
            ]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to update the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }

    /**
     * Creating the thread of given category ID and forum ID.
     * @param integer $cid
     * @param integer $fid
     * @return string|\yii\web\Response
     */
    public function actionNewThread($cid = null, $fid = null)
    {
        if (!User::can(Rbac::PERM_CREATE_THREAD)) {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to create a new thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
        else {
            if (!is_numeric($cid) || $cid < 1 || !is_numeric($fid) || $fid < 1) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
                return $this->redirect(['default/index']);
            }

            $category = Category::findOne((int)$cid);

            if (!$category) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
                return $this->redirect(['default/index']);
            }
            else {
                $forum = Forum::find()->where(['id' => (int)$fid, 'category_id' => $category->id])->limit(1)->one();
                if (!$forum) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
                    return $this->redirect(['default/index']);
                }
                else {
                    $model = new Thread;
                    $model->setScenario('new');

                    $postData = Yii::$app->request->post();
                    
                    $preview = '';
                    $model->subscribe = 1;
                    
                    if ($model->load($postData)) {

                        $model->posts       = 0;
                        $model->views       = 0;
                        $model->category_id = $category->id;
                        $model->forum_id    = $forum->id;
                        $model->author_id   = User::loggedId();

                        if ($model->validate()) {
                            if (isset($postData['preview-button'])) {
                                $preview = $model->post;
                            }
                            else {
                                $transaction = Thread::getDb()->beginTransaction();
                                try {
                                    if ($model->save()) {
                                        $forum->updateCounters(['threads' => 1]);

                                        $post            = new Post;
                                        $post->content   = $model->post;
                                        $post->thread_id = $model->id;
                                        $post->forum_id  = $model->forum_id;
                                        $post->author_id = User::loggedId();
                                        $post->likes     = 0;
                                        $post->dislikes  = 0;
                                        
                                        if ($post->save()) {
                                            $post->markSeen();
                                            $forum->updateCounters(['posts' => 1]);
                                            $model->updateCounters(['posts' => 1]);
                                            
                                            $model->touch('new_post_at');
                                            $model->touch('edited_post_at');
                                            
                                            if ($model->subscribe) {
                                                $subscription = new Subscription();
                                                $subscription->user_id   = User::loggedId();
                                                $subscription->thread_id = $model->id;
                                                $subscription->post_seen = Subscription::POST_SEEN;
                                                $subscription->save();
                                            }
                                        }
                                    }

                                    $transaction->commit();
                                    
                                    Cache::getInstance()->delete('forum.threadscount');
                                    Cache::getInstance()->delete('forum.postscount');
                                    Cache::getInstance()->deleteElement('user.threadscount', User::loggedId());
                                    Cache::getInstance()->deleteElement('user.postscount', User::loggedId());
                                    Cache::getInstance()->delete('forum.latestposts');
                                    
                                    Log::info('Thread added', $model->id, __METHOD__);
                                    $this->success(Yii::t('podium/flash', 'New thread has been created.'));

                                    return $this->redirect(['thread', 
                                                'cid'  => $category->id,
                                                'fid'  => $forum->id, 
                                                'id'   => $model->id,
                                                'slug' => $model->slug]);
                                }
                                catch (Exception $e) {
                                    $transaction->rollBack();
                                    Log::error($e->getMessage(), null, __METHOD__);
                                    $this->error(Yii::t('podium/flash', 'Sorry! There was an error while creating the thread. Contact administrator about this problem.'));
                                }
                            }
                        }
                    }
                }
            }

            return $this->render('new-thread', [
                        'preview'  => $preview,
                        'model'    => $model,
                        'category' => $category,
                        'forum'    => $forum,
            ]);
        }
    }
    
    /**
     * Pinning the thread of given category ID, forum ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return \yii\web\Response
     */
    public function actionPin($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list(,, $thread) = $verify;
        
        if (User::can(Rbac::PERM_PIN_THREAD, ['item' => $thread])) {
            if ($thread->pinned) {
                $thread->pinned = 0;
            }
            else {
                $thread->pinned = 1;
            }
            if ($thread->save()) {
                if ($thread->pinned) {
                    Log::info('Thread pinned', $thread->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Thread has been pinned.'));
                }
                else {
                    Log::info('Thread unpinned', $thread->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Thread has been unpinned.'));
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! There was an error while updating the thread.'));
            }
            return $this->redirect(['default/thread', 'cid' => $cid, 'fid' => $fid, 'id' => $id, 'slug' => $slug]);
        }
        else {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to update the thread.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
    }
    
    /**
     * Creating the post of given category ID, forum ID and thread ID.
     * This can be reply to selected post of given ID.
     * @param integer $cid
     * @param integer $fid
     * @param integer $tid
     * @param integer $pid
     * @return string|\yii\web\Response
     */
    public function actionPost($cid = null, $fid = null, $tid = null, $pid = null)
    {
        if (!User::can(Rbac::PERM_CREATE_POST)) {
            if (Yii::$app->user->isGuest) {
                $this->warning(Yii::t('podium/flash', 'Please sign in to post a reply.'));
                return $this->redirect(['account/login']);
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! You do not have the required permission to perform this action.'));
                return $this->redirect(['default/index']);
            }
        }
        else {
            if (!is_numeric($cid) || $cid < 1 || !is_numeric($fid) || $fid < 1 || !is_numeric($tid) || $tid < 1) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
                return $this->redirect(['default/index']);
            }

            $category = Category::findOne((int)$cid);

            if (!$category) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
                return $this->redirect(['default/index']);
            }
            else {
                $forum = Forum::find()->where(['id' => (int)$fid, 'category_id' => $category->id])->limit(1)->one();

                if (!$forum) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
                    return $this->redirect(['default/index']);
                }
                else {
                    $thread = Thread::find()->where(['id' => (int)$tid, 'category_id' => $category->id, 'forum_id' => $forum->id])->limit(1)->one();

                    if (!$thread) {
                        $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
                        return $this->redirect(['default/index']);
                    }
                    else {
                        if ($thread->locked == 0 || ($thread->locked == 1 && User::can(Rbac::PERM_UPDATE_THREAD, ['item' => $thread]))) {
                            
                            $model = new Post;
                            $model->subscribe = 1;

                            $postData = Yii::$app->request->post();

                            $replyFor = null;
                            if (is_numeric($pid) && $pid > 0) {
                                $replyFor = Post::findOne((int)$pid);
                                if ($replyFor) {

                                    if (isset($postData['quote']) && !empty($postData['quote'])) {
                                        $model->content = Helper::prepareQuote($replyFor, $postData['quote']);
                                    }
                                    else {
                                        $model->content = Helper::prepareQuote($replyFor);
                                    }                            
                                }
                            }

                            $preview = '';
                            $previous = Post::find()->where(['thread_id' => $thread->id])->orderBy(['id' => SORT_DESC])->limit(1)->one();

                            if ($model->load($postData)) {

                                $model->thread_id = $thread->id;
                                $model->forum_id  = $forum->id;
                                $model->author_id = User::loggedId();

                                if ($model->validate()) {
                                    if (isset($postData['preview-button'])) {
                                        $preview = $model->content;
                                    }
                                    else {
                                        $transaction = Post::getDb()->beginTransaction();
                                        try {
                                            $id = null;
                                            
                                            if ($previous->author_id == User::loggedId()) {
                                                $previous->content .= '<hr>' . $model->content;
                                                $previous->edited = 1;
                                                $previous->edited_at = time();

                                                if ($previous->save()) {
                                                    $previous->markSeen();
                                                    $thread->touch('edited_post_at');
                                                    $id = $previous->id;
                                                }
                                            }
                                            else {
                                                if ($model->save(false)) {
                                                    $model->markSeen();
                                                    $forum->updateCounters(['posts' => 1]);
                                                    $thread->updateCounters(['posts' => 1]);
                                                    $thread->touch('new_post_at');
                                                    $thread->touch('edited_post_at');
                                                    $id = $model->id;
                                                }
                                            }
                                            
                                            if ($id !== null) {
                                                Subscription::notify($thread->id);
                                                
                                                if ($model->subscribe && !$model->thread->subscription) {
                                                    $subscription = new Subscription();
                                                    $subscription->user_id   = User::loggedId();
                                                    $subscription->thread_id = $model->thread->id;
                                                    $subscription->post_seen = Subscription::POST_SEEN;
                                                    $subscription->save();
                                                }
                                                
                                                $transaction->commit();

                                                Cache::getInstance()->delete('forum.postscount');
                                                Cache::getInstance()->deleteElement('user.postscount', User::loggedId());
                                                Cache::getInstance()->delete('forum.latestposts');

                                                Log::info('Post added', $model->id, __METHOD__);
                                                $this->success(Yii::t('podium/flash', 'New reply has been added.'));

                                                return $this->redirect(['default/show', 'id' => $id]);
                                            }
                                            else {
                                                throw new Exception('Saved Post ID missing.');
                                            }
                                        }
                                        catch (Exception $e) {
                                            $transaction->rollBack();
                                            Log::error($e->getMessage(), null, __METHOD__);
                                            $this->error(Yii::t('podium/flash', 'Sorry! There was an error while adding the reply. Contact administrator about this problem.'));
                                        }
                                    }
                                }
                            }

                            return $this->render('post', [
                                        'replyFor' => $replyFor,
                                        'preview'  => $preview,
                                        'model'    => $model,
                                        'category' => $category,
                                        'forum'    => $forum,
                                        'thread'   => $thread,
                                        'previous' => $previous,
                            ]);
                        }
                        else {
                            $this->info(Yii::t('podium/flash', 'This thread is locked.'));
                            return $this->redirect(['default/thread', 'cid' => $category->id, 'fid' => $forum->id, 'id' => $thread->id, 'slug' => $thread->slug]);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Reporting the post of given category ID, forum ID, thread ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $tid
     * @param integer $pid
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionReport($cid = null, $fid = null, $tid = null, $pid = null, $slug = null)
    {
        if (!Yii::$app->user->isGuest) {
            if (!is_numeric($cid) || $cid < 1 || !is_numeric($fid) || $fid < 1 || !is_numeric($tid) || $tid < 1 || !is_numeric($pid) || $pid < 1 || empty($slug)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                return $this->redirect(['default/index']);
            }

            $category = Category::findOne((int)$cid);

            if (!$category) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                return $this->redirect(['default/index']);
            }
            else {
                $forum = Forum::find()->where(['id' => (int)$fid, 'category_id' => $category->id])->limit(1)->one();

                if (!$forum) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                    return $this->redirect(['default/index']);
                }
                else {
                    $thread = Thread::find()->where(['id' => (int)$tid, 'category_id' => $category->id, 'forum_id' => $forum->id, 'slug' => $slug])->limit(1)->one();

                    if (!$thread) {
                        $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                        return $this->redirect(['default/index']);
                    }
                    else {
                        $post = Post::find()->where(['id' => (int)$pid, 'forum_id' => $forum->id, 'thread_id' => $thread->id])->limit(1)->one();

                        if (!$post) {
                            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                            return $this->redirect(['default/index']);
                        }
                        else {
                            if ($post->author_id == User::loggedId()) {
                                $this->info(Yii::t('podium/flash', 'You can not report your own post. Please contact the administrator or moderators if you have got any concerns regarding your post.'));
                                return $this->redirect(['default/thread', 'cid' => $category->id, 'fid' => $forum->id, 'id' => $thread->id, 'slug' => $thread->slug]);
                            }
                            else {

                                $model = new Message;
                                $model->setScenario('report');
                                
                                if ($model->load(Yii::$app->request->post())) {

                                    if ($model->validate()) {

                                        try {

                                            $mods    = $forum->getMods();
                                            $package = [];
                                            foreach ($mods as $mod) {
                                                if ($mod != User::loggedId()) {
                                                    $package[] = [
                                                        'sender_id'       => User::loggedId(),
                                                        'receiver_id'     => $mod,
                                                        'topic'           => Yii::t('podium/view', 'Complaint about the post #{id}', ['id' => $post->id]),
                                                        'content'         => $model->content . '<hr>' . 
                                                            Html::a(Yii::t('podium/view', 'Direct link to this post'), ['default/show', 'id' => $post->id]) . '<hr>' .
                                                            '<strong>' . Yii::t('podium/view', 'Post contents') . '</strong><br><blockquote>' . $post->content . '</blockquote>',
                                                        'sender_status'   => Message::STATUS_REMOVED,
                                                        'receiver_status' => Message::STATUS_NEW,
                                                        'created_at'      => time(),
                                                        'updated_at'      => time(),
                                                    ];
                                                }
                                            }
                                            if (!empty($package)) {
                                                Yii::$app->db->createCommand()->batchInsert(Message::tableName(), 
                                                    ['sender_id', 'receiver_id', 'topic', 'content', 'sender_status', 'receiver_status', 'created_at', 'updated_at'], 
                                                        array_values($package))->execute();
                                                
                                                Cache::getInstance()->delete('user.newmessages');
                                                
                                                Log::info('Post reported', $post->id, __METHOD__);
                                                $this->success(Yii::t('podium/flash', 'Thank you for your report. The moderation team will take a look at this post.'));
                                                return $this->redirect(['default/thread', 'cid' => $category->id, 'fid' => $forum->id, 'id' => $thread->id, 'slug' => $thread->slug]);
                                            }
                                            else {
                                                $this->warning(Yii::t('podium/flash', 'Apparently there is no one we can send this report to except you and you are already reporting it so...'));
                                            }
                                        }
                                        catch (Exception $e) {
                                            Log::error($e->getMessage(), null, __METHOD__);
                                            $this->error(Yii::t('podium/flash', 'Sorry! There was an error while notifying the moderation team. Contact administrator about this problem.'));
                                        }
                                    }
                                }

                                return $this->render('report', [
                                            'model'    => $model,
                                            'category' => $category,
                                            'forum'    => $forum,
                                            'thread'   => $thread,
                                            'post'     => $post,
                                ]);
                            }
                        }
                    }
                }
            }
        }
        else {
            $this->warning(Yii::t('podium/flash', 'Please sign in to report the post.'));
            return $this->redirect(['account/login']);
        }
    }
    
    /**
     * Main RSS channel.
     * @return string
     */
    public function actionRss()
    {
        $response = Yii::$app->getResponse();
        $headers = $response->getHeaders();

        $headers->set('Content-Type', 'application/rss+xml; charset=utf-8');

        $response->content = RssView::widget([
            'dataProvider' => (new Forum)->search(null, true),
            'channel'      => [
                'title'       => Config::getInstance()->get('name'),
                'link'        => Url::to(['default/index'], true),
                'description' => Config::getInstance()->get('meta_description'),
                'language'    => Yii::$app->language
            ],
            'items' => [
                'title' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return Html::encode($model->latest->thread->name);
                        }
                        else {
                            return Html::encode($model->name);
                        }
                    },
                'description' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return StringHelper::truncateWords($model->latest->content, 50, '...', true);
                        }
                        return '';
                    },
                'link' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return Url::to(['default/show', 'id' => $model->latest->id], true);
                        }
                        return Url::to(['default/forum', 'cid' => $model->category_id, 'id' => $model->id, 'slug' => $model->slug], true);
                    },
                'author' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return $model->latest->author->username;
                        }
                        return 'Podium';
                    },
                'guid' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return Url::to(['default/show', 'id' => $model->latest->id], true) . ' ' . Yii::$app->formatter->asDatetime($model->latest->updated_at, 'php:' . DATE_RSS);
                        }
                        else {
                            return Url::to(['default/forum', 'cid' => $model->category_id, 'id' => $model->id, 'slug' => $model->slug], true) . ' ' . Yii::$app->formatter->asDatetime($model->updated_at, 'php:' . DATE_RSS);
                        }
                    },
                'pubDate' => function ($model, $widget) {
                        if (!empty($model->latest)) {
                            return Yii::$app->formatter->asDatetime($model->latest->updated_at, 'php:' . DATE_RSS);
                        }
                        else {
                            return Yii::$app->formatter->asDatetime($model->updated_at, 'php:' . DATE_RSS);
                        }
                    }
            ]
        ]);
    }
    
    /**
     * Searching through the forum.
     * @return string
     */
    public function actionSearch()
    {
        $dataProvider = null;
        $searchModel  = new Vocabulary;
        if ($searchModel->load(Yii::$app->request->get(), '')) {
            $dataProvider = $searchModel->search();
        }
        else {
            $model = new SearchForm;
            $model->match   = 'all';
            $model->type    = 'posts';
            $model->display = 'topics';
            
            $categories = Category::find()->orderBy(['name' => SORT_ASC])->all();
            $forums     = Forum::find()->orderBy(['name' => SORT_ASC])->all();
            
            $list = [];
            foreach ($categories as $cat) {
                $catlist = [];
                foreach ($forums as $for) {
                    if ($for->category_id == $cat->id) {
                        $catlist[$for->id] = '|-- ' . Html::encode($for->name);
                    }
                }
                $list[Html::encode($cat->name)] = $catlist;
            }
            
            if ($model->load(Yii::$app->request->post()) && $model->validate()) {
                if (empty($model->query) && empty($model->author)) {
                    $this->error(Yii::t('podium/flash', "You have to enter words or author's name first."));
                }
                else {
                    $stop = false;
                    if (!empty($model->query)) {
                        $words = explode(' ', preg_replace('/\s+/', ' ', $model->query));
                        $checkedWords = [];
                        foreach ($words as $word) {
                            if (mb_strlen($word, 'UTF-8') > 2) {
                                $checkedWords[] = $word;
                            }
                        }
                        $model->query = implode(' ', $checkedWords);
                        if (mb_strlen($model->query, 'UTF-8') < 3) {
                            $this->error(Yii::t('podium/flash', 'You have to enter word at least 3 characters long.'));
                            $stop = true;
                        }
                    }
                    if (!$stop) {
                        $dataProvider = $model->searchAdvanced();
                    }
                }
            }
            
            return $this->render('search', [
                'model'        => $model,
                'list'         => $list,
                'dataProvider' => $dataProvider,
                'query'        => $model->query,
                'author'       => $model->author,
            ]);
        }
        
        return $this->render('search', [
            'dataProvider' => $dataProvider,
            'query'        => $searchModel->query,
        ]);
    }
    
    /**
     * Direct link for the post of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionShow($id = null)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }
        
        $post = Post::findOne((int)$id);
        if (!$post) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }
        
        if ($post->thread) {
            
            $url = [
                'default/thread', 
                'cid'  => $post->thread->category_id,
                'fid'  => $post->forum_id, 
                'id'   => $post->thread_id, 
                'slug' => $post->thread->slug
            ];
            
            try {
                $count = (new Query)->from(Post::tableName())->where(['and', ['thread_id' => $post->thread_id], ['<', 'id', $post->id]])->orderBy(['id' => SORT_ASC])->count();
                $page = floor($count / 10) + 1;
                
                if ($page > 1) {
                    $url['page'] = $page;
                }
                $url['#'] = 'post' . $post->id;

                return $this->redirect($url);
            }
            catch (Exception $e) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
                return $this->redirect(['default/index']);
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['default/index']);
        }        
    }

    /**
     * Displaying the thread of given category ID, forum ID, own ID and slug.
     * @param integer $cid
     * @param integer $fid
     * @param integer $id
     * @param string $slug
     * @return string|\yii\web\Response
     */
    public function actionThread($cid = null, $fid = null, $id = null, $slug = null)
    {
        $verify = $this->_verifyThread($cid, $fid, $id, $slug);
        
        if ($verify === false) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['default/index']);
        }

        list($category, $forum, $thread) = $verify;
        
        $keywords = $forum->keywords;
        if (!$keywords) {
            $keywords = $category->keywords;
        }
        $description = $forum->description;
        if (!$description) {
            $description = $category->description;
        }
        $this->setMetaTags($keywords, $description);
        
        $dataProvider = (new Post)->search($forum->id, $thread->id);
        $model = new Post;
        $model->subscribe = 1;

        return $this->render('thread', [
                    'model'        => $model,
                    'dataProvider' => $dataProvider,
                    'category'     => $category,
                    'forum'        => $forum,
                    'thread'       => $thread,
        ]);
    }

    /**
     * Voting on the post.
     * @return string|\yii\web\Response
     */
    public function actionThumb()
    {
        if (Yii::$app->request->isAjax) {
            
            $data = [
                'error' => 1,
                'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Error while voting on this post!'), ['class' => 'text-danger']),
            ];
            
            if (!Yii::$app->user->isGuest) {
                $postId = Yii::$app->request->post('post');
                $thumb  = Yii::$app->request->post('thumb');
                
                if (is_numeric($postId) && $postId > 0 && in_array($thumb, ['up', 'down'])) {
                    
                    $post = Post::findOne((int)$postId);
                    if ($post) {
                        if ($post->thread->locked) {
                            $data = [
                                'error' => 1,
                                'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'This thread is locked.'), ['class' => 'text-info']),
                            ];
                        }
                        else {
                            if ($post->author_id == User::loggedId()) {
                                return Json::encode([
                                    'error' => 1,
                                    'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'You can not vote on your own post!'), ['class' => 'text-info']),
                                ]);
                            }

                            $count = 0;
                            $votes = Cache::getInstance()->get('user.votes.' . User::loggedId());
                            if ($votes !== false) {
                                if ($votes['expire'] < time()) {
                                    $votes = false;
                                }
                                elseif ($votes['count'] >= 10) {
                                    return Json::encode([
                                        'error' => 1,
                                        'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', '{max} votes per hour limit reached!', ['max' => 10]), ['class' => 'text-danger']),
                                    ]);
                                }
                                else {
                                    $count = $votes['count'];
                                }
                            }

                            if ($post->thumb) {
                                if ($post->thumb->thumb == 1 && $thumb == 'down') {
                                    $post->thumb->thumb = -1;
                                    if ($post->thumb->save()) {
                                        $post->updateCounters(['likes' => -1, 'dislikes' => 1]);
                                    }
                                }
                                elseif ($post->thumb->thumb == -1 && $thumb == 'up') {
                                    $post->thumb->thumb = 1;
                                    if ($post->thumb->save()) {
                                        $post->updateCounters(['likes' => 1, 'dislikes' => -1]);
                                    }
                                }
                            }
                            else {
                                $postThumb          = new PostThumb;
                                $postThumb->post_id = $post->id;
                                $postThumb->user_id = User::loggedId();
                                $postThumb->thumb   = $thumb == 'up' ? 1 : -1;
                                if ($postThumb->save()) {
                                    if ($thumb == 'up') {
                                        $post->updateCounters(['likes' => 1]);
                                    }
                                    else {
                                        $post->updateCounters(['dislikes' => 1]);
                                    }
                                }
                            }
                            $data = [
                                'error'    => 0,
                                'likes'    => '+' . $post->likes,
                                'dislikes' => '-' . $post->dislikes,
                                'summ'     => $post->likes - $post->dislikes,
                                'msg'      => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-ok-circle']) . ' ' . Yii::t('podium/view', 'Your vote has been saved!'), ['class' => 'text-success']),
                            ];
                            if ($count == 0) {
                                Cache::getInstance()->set('user.votes.' . User::loggedId(), ['count' => 1, 'expire' => time() + 3600]);
                            }
                            else {
                                Cache::getInstance()->setElement('user.votes.' . User::loggedId(), 'count', $count + 1);
                            }
                        }
                    }
                }
            }
            else {
                $data = [
                    'error' => 1,
                    'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Please sign in to vote on this post'), ['class' => 'text-info']),
                ];
            }
            
            return Json::encode($data);
        }
        else {
            return $this->redirect(['default/index']);
        }
    }
    
    /**
     * Setting meta tags.
     * @param string $keywords
     * @param string $description
     */
    public function setMetaTags($keywords = '', $description = '')
    {
        if ($keywords == '') {
            $keywords = Config::getInstance()->get('meta_keywords');
        }
        if ($keywords) {
            $this->getView()->registerMetaTag([
                'name'    => 'keywords',
                'content' => $keywords
            ]);
        }
        
        if ($description == '') {
            $description = Config::getInstance()->get('meta_description');
        }
        if ($description) {
            $this->getView()->registerMetaTag([
                'name'    => 'description',
                'content' => $description
            ]);
        }
    }
    
    /**
     * Listing all unread posts.
     * @return string|\yii\web\Response
     */
    public function actionUnreadPosts()
    {
        if (Yii::$app->user->isGuest) {
            $this->info(Yii::t('podium/flash', 'This page is available for registered users only.'));
            return $this->redirect(['account/login']);
        }
        return $this->render('unread-posts');
    }
    
    /**
     * Marking all unread posts as seen.
     * @return string|\yii\web\Response
     */
    public function actionMarkSeen()
    {
        if (Yii::$app->user->isGuest) {
            $this->info(Yii::t('podium/flash', 'This action is available for registered users only.'));
            return $this->redirect(['account/login']);
        }
        
        try {
            $loggedId = User::loggedId();
            $batch = [];
            $threadsPrevMarked = Thread::find()->joinWith('threadView')
                    ->where([
                        'and',
                        ['user_id' => User::loggedId()],
                        [
                            'or',
                            new Expression('`new_last_seen` < `new_post_at`'),
                            new Expression('`edited_last_seen` < `edited_post_at`')
                        ],
                    ]);
            $time = time();
            foreach ($threadsPrevMarked->each() as $thread) {
                $batch[] = $thread->id;
            }
            if (!empty($batch)) {
                Yii::$app->db->createCommand()->update(ThreadView::tableName(), [
                        'new_last_seen' => $time, 
                        'edited_last_seen' => $time
                    ], ['thread_id' => $batch, 'user_id' => $loggedId])->execute();
            }

            $batch = [];
            $threadsNew = Thread::find()->joinWith('threadView')->where(['user_id' => null]);
            foreach ($threadsNew->each() as $thread) {
                $batch[] = [$loggedId, $thread->id, $time, $time];
            }
            if (!empty($batch)) {
                Yii::$app->db->createCommand()->batchInsert(ThreadView::tableName(), ['user_id', 'thread_id', 'new_last_seen', 'edited_last_seen'], $batch)->execute();
            }
            $this->success(Yii::t('podium/flash', 'All unread threads have been marked as seen.'));
            return $this->redirect(['default/index']);
        }
        catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            $this->error(Yii::t('podium/flash', 'Sorry! There was an error while marking threads as seen. Contact administrator about this problem.'));
            return $this->redirect(['default/unread-posts']);
        }
    }
}
