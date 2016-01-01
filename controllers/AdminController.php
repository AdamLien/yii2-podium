<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\controllers;

use bizley\podium\components\Cache;
use bizley\podium\log\Log;
use bizley\podium\models\Activity;
use bizley\podium\models\Category;
use bizley\podium\models\ConfigForm;
use bizley\podium\models\Content;
use bizley\podium\models\Forum;
use bizley\podium\models\ForumSearch;
use bizley\podium\models\LogSearch;
use bizley\podium\models\Mod;
use bizley\podium\models\Post;
use bizley\podium\models\User;
use bizley\podium\models\UserSearch;
use bizley\podium\rbac\Rbac;
use Exception;
use Yii;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\helpers\Html;

/**
 * Podium Admin controller
 * All actions concerning module administration.
 * 
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */
class AdminController extends BaseController
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
                            'roles' => [Rbac::ROLE_ADMIN]
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Banning the user of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionBan($id = null)
    {
        if (User::can(Rbac::PERM_BAN_USER)) {
            $model = User::findOne((int)$id);

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find Member with this ID.'));
            }
            elseif ($model->id == User::loggedId()) {
                $this->error(Yii::t('podium/flash', 'Sorry! You can not ban or unban your own account.'));
            }
            else {
                if ($model->status == User::STATUS_ACTIVE) {
                    if ($model->ban()) {
                        Cache::getInstance()->delete('members.fieldlist');
                        Log::info('User banned', $model->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'User has been banned.'));
                    }
                    else {
                        Log::error('Error while banning user', $model->id, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was some error while banning the user.'));
                    }
                }
                elseif ($model->status == User::STATUS_BANNED) {
                    if ($model->unban()) {
                        Cache::getInstance()->delete('members.fieldlist');
                        Log::info('User unbanned', $model->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'User has been unbanned.'));
                    }
                    else {
                        Log::error('Error while unbanning user', $model->id, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was some error while unbanning the user.'));
                    }
                }
                else {
                    $this->error(Yii::t('podium/flash', 'Sorry! User has got the wrong status.'));
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['admin/members']);
    }
    
    /**
     * Listing categories.
     * @return string
     */
    public function actionCategories()
    {
        return $this->render('categories', ['dataProvider' => (new Category())->show()]);
    }
    
    /**
     * Clearing all cache.
     * @return string
     */
    public function actionClear()
    {
        if (Cache::getInstance()->flush()) {
            $this->success(Yii::t('podium/flash', 'Cache has been cleared.'));
        }
        else {
            $this->error(Yii::t('podium/flash', 'Sorry! There was some error while clearing the cache.'));
        }
        
        return $this->redirect(['admin/settings']);
    }
    
    /**
     * Listing the contents.
     * @param string $name content name
     * @return string|\yii\web\Response
     */
    public function actionContents($name = '')
    {
        $allowed = ['terms', 'email-reg', 'email-new', 'email-react', 'email-pass', 'email-sub'];

        if ($name == '' || !in_array($name, $allowed)) {
            $name = 'terms';
        }        
        
        $model = Content::find()->where(['name' => $name])->limit(1)->one();
        if (!$model) {
            $model = new Content();
            $model->name = $name;
        }        

        if ($model->load(Yii::$app->request->post())) {
            if (User::can(Rbac::PERM_CHANGE_SETTINGS)) {
                if ($model->save()) {
                    $this->success(Yii::t('podium/flash', 'Content has been saved.'));
                }
                else {
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while saving the content.'));
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
            }
            return $this->refresh();
        }

        return $this->render('contents', ['model' => $model]);
    }
    
    /**
     * Deleting the user of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionDelete($id = null)
    {
        if (User::can(Rbac::PERM_DELETE_USER)) {
            $model = User::findOne((int)$id);

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find Member with this ID.'));
            }
            elseif ($model->id == User::loggedId()) {
                $this->error(Yii::t('podium/flash', 'Sorry! You can not delete your own account.'));
            }
            else {
                if ($model->delete()) {
                    Cache::getInstance()->delete('members.fieldlist');
                    Cache::getInstance()->delete('forum.memberscount');
                    Activity::deleteUser($model->id);
                    Log::info('User deleted', $model->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'User has been deleted.'));
                }
                else {
                    Log::error('Error while deleting user', $model->id, __METHOD__);
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while deleting the user.'));
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['admin/members']);
    }
    
    /**
     * Deleting the category of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionDeleteCategory($id = null)
    {
        if (User::can(Rbac::PERM_DELETE_CATEGORY)) {
            $model = Category::findOne((int)$id);

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
            }
            else {
                if ($model->delete()) {
                    Cache::getInstance()->delete('forum.threadscount');
                    Cache::getInstance()->delete('forum.postscount');
                    Cache::getInstance()->delete('forum.latestposts');
                    Log::info('Category deleted', $model->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Category has been deleted.'));
                }
                else {
                    Log::error('Error while deleting category', $model->id, __METHOD__);
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while deleting the category.'));
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['admin/categories']);
    }
    
    /**
     * Deleting the forum of given ID.
     * @param integer $cid parent category ID
     * @param integer $id forum ID
     * @return \yii\web\Response
     */
    public function actionDeleteForum($cid = null, $id = null)
    {
        if (User::can(Rbac::PERM_DELETE_FORUM)) {
            $category = Category::findOne((int)$cid);

            if (empty($category)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
                return $this->redirect(['admin/categories']);
            }

            $model = Forum::find()->where(['id' => (int)$id, 'category_id' => $category->id])->limit(1)->one();

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find Forum with this ID.'));
            }
            else {
                if ($model->delete()) {
                    Cache::getInstance()->delete('forum.threadscount');
                    Cache::getInstance()->delete('forum.postscount');
                    Cache::getInstance()->delete('forum.latestposts');
                    Log::info('Forum deleted', $model->id, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Forum has been deleted.'));
                }
                else {
                    Log::error('Error while deleting forum', $model->id, __METHOD__);
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while deleting the forum.'));
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['admin/forums', 'cid' => $category->id]);
    }
    
    /**
     * Demoting the user of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionDemote($id = null)
    {
        if (User::can(Rbac::PERM_PROMOTE_USER)) {
            $model = User::findOne((int)$id);

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find User with this ID.'));
            }
            else {
                if ($model->role != User::ROLE_MODERATOR) {
                    $this->error(Yii::t('podium/flash', 'You can only demote Moderators to Members.'));
                }
                else {
                    $transaction = User::getDb()->beginTransaction();
                    try {
                        if ($model->demoteTo(User::ROLE_MEMBER)) {
                            if (Yii::$app->authManager->getRolesByUser($model->id)) {
                                Yii::$app->authManager->revoke(Yii::$app->authManager->getRole(Rbac::ROLE_MODERATOR), $model->id);
                            }
                            if (Yii::$app->authManager->assign(Yii::$app->authManager->getRole(Rbac::ROLE_USER), $model->id)) {
                                Yii::$app->db->createCommand()->delete(Mod::tableName(), 'user_id = :id', [':id' => $model->id])->execute();
                                Activity::updateRole($model->id, User::ROLE_MEMBER);
                                $transaction->commit();
                                Log::info('User demoted', $model->id, __METHOD__);
                                $this->success(Yii::t('podium/flash', 'User has been demoted.'));
                                return $this->redirect(['admin/members']);
                            }
                        }
                        Log::error('Error while demoting user', $model->id, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while demoting the user.'));
                    }
                    catch (Exception $e) {
                        $transaction->rollBack();
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while demoting the user.'));
                    }
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['admin/members']);
    }
    
    /**
     * Editing the category of given ID.
     * @param integer $id
     * @return string|\yii\web\Response
     */
    public function actionEditCategory($id = null)
    {
        $model = Category::findOne((int)$id);

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
            return $this->redirect(['admin/categories']);
        }
        else {
            if ($model->load(Yii::$app->request->post())) {
                if (User::can(Rbac::PERM_UPDATE_CATEGORY)) {
                    if ($model->save()) {
                        Log::info('Category updated', $model->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'Category has been updated.'));
                    }
                    else {
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while updating the category.'));
                    }
                }
                else {
                    $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
                }
                return $this->redirect(['admin/categories']);
            }
            
            return $this->render('category', [
                        'model'      => $model,
                        'categories' => Category::find()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all()
            ]);
        }
    }
    
    /**
     * Editing the forum of given ID.
     * @param integer $cid parent category ID
     * @param integer $id forum ID
     * @return string|\yii\web\Response
     */
    public function actionEditForum($cid = null, $id = null)
    {
        $category = Category::findOne((int)$cid);

        if (empty($category)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
            return $this->redirect(['admin/categories']);
        }

        $model = Forum::find()->where(['id' => (int)$id, 'category_id' => $category->id])->limit(1)->one();

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Forum with this ID.'));
            return $this->redirect(['admin/forums', 'cid' => $category->id]);
        }
        else {
            if ($model->load(Yii::$app->request->post())) {
                if (User::can(Rbac::PERM_UPDATE_FORUM)) {
                    if ($model->save()) {
                        Log::info('Forum updated', $model->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'Forum has been updated.'));
                    }
                    else {
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while updating the forum.'));
                    }
                }
                else {
                    $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
                }
                return $this->redirect(['admin/forums', 'cid' => $model->category_id]);
            }

            return $this->render('forum', [
                        'model'      => $model,
                        'forums'     => Forum::find()->where(['category_id' => $category->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all(),
                        'categories' => Category::find()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all()
            ]);
        }
    }
    
    /**
     * Listing the forums of given category ID.
     * @param integer $cid parent category ID
     * @return string|\yii\web\Response
     */
    public function actionForums($cid = null)
    {
        $model = Category::findOne((int)$cid);

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
            return $this->redirect(['admin/categories']);
        }

        return $this->render('forums', [
                    'model'      => $model,
                    'categories' => Category::find()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all(),
                    'forums'     => Forum::find()->where(['category_id' => $model->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all()
        ]);
    }
    
    /**
     * Dashboard.
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index', [
            'members' => User::find()->orderBy(['id' => SORT_DESC])->limit(10)->all(),
            'posts'   => Post::find()->orderBy(['id' => SORT_DESC])->limit(10)->all()
        ]);
    }

    /**
     * Listing the logs.
     * @return string
     */
    public function actionLogs()
    {
        $searchModel  = new LogSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->get());

        return $this->render('logs', [
                    'dataProvider' => $dataProvider,
                    'searchModel'  => $searchModel,
        ]);
    }
    
    /**
     * Listing the users.
     * @return string
     */
    public function actionMembers()
    {
        $searchModel  = new UserSearch;
        $dataProvider = $searchModel->search(Yii::$app->request->get());
        
        return $this->render('members', [
                    'dataProvider' => $dataProvider,
                    'searchModel'  => $searchModel,
        ]);
    }
    
    /**
     * Adding/removing forum from the moderation list for user of given ID.
     * @param integer $uid user ID
     * @param integer $fid forum ID
     * @return \yii\web\Response
     */
    public function actionMod($uid = null, $fid = null)
    {
        if (User::can(Rbac::PERM_PROMOTE_USER)) {
            if (!is_numeric($uid) || $uid < 1 || !is_numeric($fid) || $fid < 1) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find the moderator or forum with this ID.'));
            }
            else {
                $mod = User::find()->where(['id' => (int)$uid, 'role' => User::ROLE_MODERATOR])->limit(1)->one();
                if (empty($mod)) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the moderator with this ID.'));
                    return $this->redirect(['admin/mods']);
                }

                $forum = Forum::findOne($fid);
                if (!$forum) {
                    $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum with this ID.'));
                }
                else {
                    try {
                        if ((new Query)->from(Mod::tableName())->where(['forum_id' => $forum->id, 'user_id' => $mod->id])->exists()) {
                            Yii::$app->db->createCommand()->delete(Mod::tableName(), ['forum_id' => $forum->id, 'user_id' => $mod->id])->execute();
                        }
                        else {
                            Yii::$app->db->createCommand()->insert(Mod::tableName(), ['forum_id' => $forum->id, 'user_id' => $mod->id])->execute();
                        }
                        Cache::getInstance()->deleteElement('forum.moderators', $forum->id);
                        Log::info('Moderator updated', $mod->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'Moderation list has been updated.'));
                    }
                    catch (Exception $e) {
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while updating the moderation list.'));
                    }
                }

                return $this->redirect(['admin/mods', 'id' => $uid]);
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }
        return $this->redirect(['admin/mods']);
    }
    
    /**
     * Listing and updating moderation list for the forum of given ID.
     * @param integer $id forum ID
     * @return string|\yii\web\Response
     */
    public function actionMods($id = null)
    {
        $mod        = null;
        $moderators = User::find()->where(['role' => User::ROLE_MODERATOR])->indexBy('id')->all();

        if (is_numeric($id) && $id > 0) {
            if (isset($moderators[$id])) {
                $mod = $moderators[$id];
            }
        }
        else {
            reset($moderators);
            $mod = current($moderators);
        }

        $searchModel  = new ForumSearch();
        $dataProvider = $searchModel->searchForMods(Yii::$app->request->get());

        $postData = Yii::$app->request->post();
        if ($postData) {
            if (User::can(Rbac::PERM_PROMOTE_USER)) {
                $mod_id    = !empty($postData['mod_id']) && is_numeric($postData['mod_id']) && $postData['mod_id'] > 0 ? $postData['mod_id'] : 0;
                $selection = !empty($postData['selection']) ? $postData['selection'] : [];
                $pre       = !empty($postData['pre']) ? $postData['pre'] : [];

                if ($mod_id != $mod->id) {
                    $this->error(Yii::t('podium/flash', 'Sorry! There was an error while selecting the moderator ID.'));
                }
                else {
                    try {
                        $add = [];
                        foreach ($selection as $select) {
                            if (!in_array($select, $pre)) {
                                if ((new Query)->from(Forum::tableName())->where(['id' => $select])->exists() && (new Query)->from(Mod::tableName())->where(['forum_id' => $select, 'user_id' => $mod->id])->exists() === false) {
                                    $add[] = [$select, $mod->id];
                                }
                            }
                        }
                        $remove = [];
                        foreach ($pre as $p) {
                            if (!in_array($p, $selection)) {
                                if ((new Query)->from(Mod::tableName())->where(['forum_id' => $p, 'user_id' => $mod->id])->exists()) {
                                    $remove[] = $p;
                                }
                            }
                        }
                        if (!empty($add)) {
                            Yii::$app->db->createCommand()->batchInsert(Mod::tableName(), ['forum_id', 'user_id'], $add)->execute();
                        }
                        if (!empty($remove)) {
                            Yii::$app->db->createCommand()->delete(Mod::tableName(), ['forum_id' => $remove, 'user_id' => $mod->id])->execute();
                        }
                        Cache::getInstance()->delete('forum.moderators');
                        Log::info('Moderators updated', null, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'Moderation list has been saved.'));
                    }
                    catch (Exception $e) {
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while saving the moderatoration list.'));
                    }

                    return $this->refresh();
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
            }
        }
        
        return $this->render('mods', [
                    'moderators'   => $moderators,
                    'mod'          => $mod,
                    'searchModel'  => $searchModel,
                    'dataProvider' => $dataProvider,
        ]);
    }
    
    /**
     * Adding new category.
     * @return string|\yii\web\Response
     */
    public function actionNewCategory()
    {
        if (User::can(Rbac::PERM_CREATE_CATEGORY)) {
            $model          = new Category();
            $model->visible = 1;
            $model->sort    = 0;

            if ($model->load(Yii::$app->request->post()) && $model->save()) {
                Log::info('Category added', $model->id, __METHOD__);
                $this->success(Yii::t('podium/flash', 'New category has been created.'));
                return $this->redirect(['admin/categories']);
            }

            return $this->render('category', [
                        'model'      => $model,
                        'categories' => Category::find()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all()
            ]);
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
            return $this->redirect(['admin/categories']);
        }
    }
    
    /**
     * Adding new forum.
     * @param integer $cid parent category ID
     * @return string|\yii\web\Response
     */
    public function actionNewForum($cid = null)
    {
        $category = Category::findOne((int)$cid);

        if (empty($category)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Category with this ID.'));
            return $this->redirect(['admin/categories']);
        }

        if (User::can(Rbac::PERM_CREATE_FORUM)) {
            $model              = new Forum();
            $model->category_id = $category->id;
            $model->visible     = 1;
            $model->sort        = 0;

            if ($model->load(Yii::$app->request->post()) && $model->save()) {
                Log::info('Forum added', $model->id, __METHOD__);
                $this->success(Yii::t('podium/flash', 'New forum has been created.'));
                return $this->redirect(['admin/forums', 'cid' => $category->id]);
            }
            else {
                return $this->render('forum', [
                            'model'      => $model,
                            'forums'     => Forum::find()->where(['category_id' => $category->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all(),
                            'categories' => Category::find()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all()
                ]);
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
            return $this->redirect(['admin/forums', 'cid' => $category->id]);
        }
    }
    
    /**
     * Promoting the user of given ID.
     * @param integer $id
     * @return \yii\web\Response
     */
    public function actionPromote($id = null)
    {
        if (User::can(Rbac::PERM_PROMOTE_USER)) {
            $model = User::findOne((int)$id);

            if (empty($model)) {
                $this->error(Yii::t('podium/flash', 'Sorry! We can not find User with this ID.'));
            }
            else {
                if ($model->role != User::ROLE_MEMBER) {
                    $this->error(Yii::t('podium/flash', 'You can only promote Members to Moderators.'));
                }
                else {
                    $transaction = User::getDb()->beginTransaction();
                    try {
                        if ($model->promoteTo(User::ROLE_MODERATOR)) {
                            if (Yii::$app->authManager->getRolesByUser($model->id)) {
                                Yii::$app->authManager->revoke(Yii::$app->authManager->getRole(Rbac::ROLE_USER), $model->id);
                            }
                            if (Yii::$app->authManager->assign(Yii::$app->authManager->getRole(Rbac::ROLE_MODERATOR), $model->id)) {
                                Activity::updateRole($model->id, User::ROLE_MODERATOR);
                                $transaction->commit();
                                Log::info('User promoted', $model->id, __METHOD__);
                                $this->success(Yii::t('podium/flash', 'User has been promoted.'));
                                return $this->redirect(['admin/mods', 'id' => $model->id]);
                            }
                        }
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while promoting the user.'));
                    }
                    catch (Exception $e) {
                        $transaction->rollBack();
                        Log::error($e->getMessage(), null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while promoting the user.'));
                    }
                }
            }
        }
        else {
            $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
        }

        return $this->redirect(['members']);
    }
    
    /**
     * Updating the module configuration.
     * @return string|\yii\web\Response
     */
    public function actionSettings()
    {
        $model = new ConfigForm();

        if ($data = Yii::$app->request->post('ConfigForm')) {
            if (User::can(Rbac::PERM_CHANGE_SETTINGS)) {
                if ($model->update($data)) {
                    Log::info('Settings updated', null, __METHOD__);
                    $this->success(Yii::t('podium/flash', 'Settings have been updated.'));
                    return $this->refresh();
                }
                else {
                    $this->error(Yii::t('podium/flash', "One of the setting's values is too long (255 characters max)."));
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'You are not allowed to perform this action.'));
            }
        }

        return $this->render('settings', ['model' => $model]);
    }
    
    /**
     * Updating the categories order.
     * @return string|\yii\web\Response
     */
    public function actionSortCategory()
    {
        if (Yii::$app->request->isAjax) {
            if (User::can(Rbac::PERM_UPDATE_CATEGORY)) {
                $modelId = Yii::$app->request->post('id');
                $new     = Yii::$app->request->post('new');

                if (is_numeric($modelId) && is_numeric($new) && $modelId > 0 && $new >= 0) {
                    $moved = Category::findOne((int) $modelId);
                    if ($moved) {
                        $query = (new Query)->from(Category::tableName())->where('id != :id')->
                                params([':id' => $moved->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->indexBy('id');
                        $next    = 0;
                        $newSort = -1;
                        try {
                            foreach ($query->each() as $id => $forum) {
                                if ($next == (int) $new) {
                                    $newSort = $next;
                                    $next++;
                                }
                                Yii::$app->db->createCommand()->update(Category::tableName(), ['sort' => $next], 'id = :id', [':id' => $id])->execute();
                                $next++;
                            }
                            if ($newSort == -1) {
                                $newSort = $next;
                            }
                            $moved->sort = $newSort;
                            if (!$moved->save()) {
                                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', "Sorry! We can not save new categories' order."), ['class' => 'text-danger']);
                            }
                            else {
                                Log::info('Categories orded updated', $moved->id, __METHOD__);
                                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-ok-circle']) . ' ' . Yii::t('podium/view', "New categories' order has been saved."), ['class' => 'text-success']);
                            }
                        }
                        catch (Exception $e) {
                            Log::error($e->getMessage(), null, __METHOD__);
                            return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', "Sorry! We can not save new categories' order."), ['class' => 'text-danger']);
                        }
                    }
                    else {
                        return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Sorry! We can not find Category with this ID.'), ['class' => 'text-danger']);
                    }
                }
                else {
                    return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Sorry! Sorting parameters are wrong.'), ['class' => 'text-danger']);
                }
            }
            else {
                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'You are not allowed to perform this action.'), ['class' => 'text-danger']);
            }
        }
        else {
            return $this->redirect(['admin/categories']);
        }
    }
    
    /**
     * Updating the forums order.
     * @return string|\yii\web\Response
     */
    public function actionSortForum()
    {
        if (Yii::$app->request->isAjax) {
            if (User::can(Rbac::PERM_UPDATE_FORUM)) {
                $modelId       = Yii::$app->request->post('id');
                $modelCategory = Yii::$app->request->post('category');
                $new           = Yii::$app->request->post('new');

                if (is_numeric($modelId) && is_numeric($modelCategory) && is_numeric($new) && $modelId > 0 && $modelCategory > 0 && $new >= 0) {
                    $moved         = Forum::findOne((int) $modelId);
                    $movedCategory = Category::findOne((int) $modelCategory);
                    if ($moved && $modelCategory && $moved->category_id == $movedCategory->id) {
                        $query = (new Query)->from(Forum::tableName())->where('id != :id AND category_id = :cid')->
                                params([':id' => $moved->id, ':cid' => $movedCategory->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->indexBy('id');
                        $next    = 0;
                        $newSort = -1;
                        try {
                            foreach ($query->each() as $id => $forum) {
                                if ($next == (int) $new) {
                                    $newSort = $next;
                                    $next++;
                                }
                                Yii::$app->db->createCommand()->update(Forum::tableName(), ['sort' => $next], 'id = :id', [':id' => $id])->execute();
                                $next++;
                            }
                            if ($newSort == -1) {
                                $newSort = $next;
                            }
                            $moved->sort = $newSort;
                            if (!$moved->save()) {
                                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', "Sorry! We can not save new forums' order."), ['class' => 'text-danger']);
                            }
                            else {
                                Log::info('Forums orded updated', $moved->id, __METHOD__);
                                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-ok-circle']) . ' ' . Yii::t('podium/view', "New forums' order has been saved."), ['class' => 'text-success']);
                            }
                        }
                        catch (Exception $e) {
                            Log::error($e->getMessage(), null, __METHOD__);
                            return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', "Sorry! We can not save new forums' order."), ['class' => 'text-danger']);
                        }
                    }
                    else {
                        return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Sorry! We can not find Forum with this ID.'), ['class' => 'text-danger']);
                    }
                }
                else {
                    return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'Sorry! Sorting parameters are wrong.'), ['class' => 'text-danger']);
                }
            }
            else {
                return Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . Yii::t('podium/view', 'You are not allowed to perform this action.'), ['class' => 'text-danger']);
            }
        }
        else {
            return $this->redirect(['admin/forums']);
        }
    }

    /**
     * Listing the details of user of given ID.
     * @param integer $id
     * @return string|\yii\web\Response
     */
    public function actionView($id = null)
    {
        $model = User::findOne((int)$id);

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Member with this ID.'));
            return $this->redirect(['admin/members']);
        }

        return $this->render('view', ['model' => $model]);
    }
}
