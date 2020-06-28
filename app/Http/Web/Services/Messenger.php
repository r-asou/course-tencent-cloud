<?php

namespace App\Http\Web\Services;

use App\Builders\ImMessageList as ImMessageListBuilder;
use App\Caches\ImHotGroupList as ImHotGroupListCache;
use App\Caches\ImHotUserList as ImHotUserListCache;
use App\Library\Paginator\Query as PagerQuery;
use App\Models\ImChatGroup as ImChatGroupModel;
use App\Models\ImChatGroupUser as ImChatGroupUserModel;
use App\Models\ImFriendGroup as ImFriendGroupModel;
use App\Models\ImFriendMessage as ImFriendMessageModel;
use App\Models\ImFriendUser as ImFriendUserModel;
use App\Models\ImGroupMessage as ImGroupMessageModel;
use App\Models\ImSystemMessage as ImSystemMessageModel;
use App\Models\User as UserModel;
use App\Repos\ImChatGroup as ImChatGroupRepo;
use App\Repos\ImChatGroupUser as ImChatGroupUserRepo;
use App\Repos\ImFriendMessage as ImFriendMessageRepo;
use App\Repos\ImFriendUser as ImFriendUserRepo;
use App\Repos\ImGroupMessage as ImGroupMessageRepo;
use App\Repos\ImSystemMessage as ImSystemMessageRepo;
use App\Repos\User as UserRepo;
use App\Validators\ImChatGroup as ImChatGroupValidator;
use App\Validators\ImChatGroupUser as ImChatGroupUserValidator;
use App\Validators\ImFriendUser as ImFriendUserValidator;
use App\Validators\ImMessage as ImMessageValidator;
use App\Validators\User as UserValidator;
use GatewayClient\Gateway;

class Messenger extends Service
{

    public function init()
    {
        $user = $this->getLoginUser();

        $mine = [
            'id' => $user->id,
            'username' => $user->name,
            'sign' => $user->sign,
            'avatar' => $user->avatar,
            'status' => 'online',
        ];

        $friend = $this->handleFriendList($user);

        $group = $this->handleGroupList($user);

        return [
            'mine' => $mine,
            'friend' => $friend,
            'group' => $group,
        ];
    }

    public function searchUsers($name)
    {
        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $params['name'] = $name;

        $sort = $pagerQuery->getSort();
        $page = $pagerQuery->getPage();
        $limit = $pagerQuery->getLimit();

        $userRepo = new UserRepo();

        $pager = $userRepo->paginate($params, $sort, $page, $limit);

        return $this->handleUserPager($pager);
    }

    public function searchGroups($name)
    {
        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $params['name'] = $name;

        $sort = $pagerQuery->getSort();
        $page = $pagerQuery->getPage();
        $limit = $pagerQuery->getLimit();

        $groupRepo = new ImChatGroupRepo();

        $pager = $groupRepo->paginate($params, $sort, $page, $limit);

        return $this->handleGroupPager($pager);
    }

    public function getHotUsers()
    {
        $cache = new ImHotUserListCache();

        $items = $cache->get();

        $pager = new \stdClass();

        $pager->total_items = count($items);
        $pager->total_pages = 1;
        $pager->items = $items;

        return $pager;
    }

    public function getHotGroups()
    {
        $cache = new ImHotGroupListCache();

        $items = $cache->get();

        $pager = new \stdClass();

        $pager->total_items = count($items);
        $pager->total_pages = 1;
        $pager->items = $items;

        return $pager;
    }

    public function getGroupUsers()
    {
        $id = $this->request->getQuery('id');

        $validator = new ImChatGroupValidator();

        $group = $validator->checkGroupCache($id);

        $groupRepo = new ImChatGroupRepo();

        $users = $groupRepo->findGroupUsers($group->id);

        if ($users->count() == 0) {
            return [];
        }

        $baseUrl = kg_ci_base_url();

        $result = [];

        foreach ($users->toArray() as $user) {
            $user['avatar'] = $baseUrl . $user['avatar'];
            $result[] = [
                'id' => $user['id'],
                'username' => $user['name'],
                'avatar' => $user['avatar'],
                'sign' => $user['sign'],
            ];
        }

        return $result;
    }

    public function getUnreadSystemMessagesCount()
    {
        $user = $this->getLoginUser();

        $userRepo = new UserRepo();

        return $userRepo->countUnreadImSystemMessages($user->id);
    }

    public function getSystemMessages()
    {
        $user = $this->getLoginUser();

        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $params['receiver_id'] = $user->id;

        $sort = $pagerQuery->getSort();
        $page = $pagerQuery->getPage();
        $limit = $pagerQuery->getLimit();

        $messageRepo = new ImSystemMessageRepo();

        return $messageRepo->paginate($params, $sort, $page, $limit);
    }

    public function getChatMessages()
    {
        $user = $this->getLoginUser();

        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $validator = new ImMessageValidator();

        $validator->checkType($params['type']);

        $sort = $pagerQuery->getSort();
        $page = $pagerQuery->getPage();
        $limit = $pagerQuery->getLimit();

        if ($params['type'] == 'friend') {

            $params['chat_id'] = ImFriendMessageModel::getChatId($user->id, $params['id']);

            $messageRepo = new ImFriendMessageRepo();

            $pager = $messageRepo->paginate($params, $sort, $page, $limit);

            return $this->handleChatMessagePager($pager);

        } elseif ($params['type'] == 'group') {

            $params['group_id'] = $params['id'];

            $messageRepo = new ImGroupMessageRepo();

            $pager = $messageRepo->paginate($params, $sort, $page, $limit);

            return $this->handleChatMessagePager($pager);
        }
    }

    public function bindUser()
    {
        $user = $this->getLoginUser();

        $user->update([
            'online' => 1,
            'active_time' => time(),
        ]);

        $clientId = $this->request->getPost('client_id');

        Gateway::$registerAddress = $this->getRegisterAddress();

        Gateway::bindUid($clientId, $user->id);

        $userRepo = new UserRepo();

        $chatGroups = $userRepo->findImChatGroups($user->id);

        if ($chatGroups->count() > 0) {
            foreach ($chatGroups as $group) {
                Gateway::joinGroup($clientId, $this->getGroupName($group->id));
            }
        }

        $this->pullUnreadFriendMessages($user);

        /**
         * @todo 隐身登录
         */
        $this->pushFriendOnlineTips($user, 'online');
    }

    public function sendMessage()
    {
        $user = $this->getLoginUser();

        $from = $this->request->getPost('from');
        $to = $this->request->getPost('to');

        $validator = new ImMessageValidator();

        $validator->checkReceiver($to['id'], $to['type']);
        $validator->checkIfBlocked($user->id, $to['id'], $to['type']);

        $from['content'] = $validator->checkContent($from['content']);

        $message = [
            'username' => $from['username'],
            'avatar' => $from['avatar'],
            'content' => $from['content'],
            'fromid' => $from['id'],
            'id' => $from['id'],
            'type' => $to['type'],
            'timestamp' => 1000 * time(),
            'mine' => false,
        ];

        if ($to['type'] == 'group') {
            $message['id'] = $to['id'];
        }

        $content = json_encode([
            'type' => 'show_chat_msg',
            'message' => $message,
        ]);

        Gateway::$registerAddress = $this->getRegisterAddress();

        if ($to['type'] == 'friend') {

            /**
             * 不推送自己给自己发送的消息
             */
            if ($user->id != $to['id']) {

                $online = Gateway::isUidOnline($to['id']);

                $messageModel = new ImFriendMessageModel();

                $messageModel->create([
                    'sender_id' => $from['id'],
                    'receiver_id' => $to['id'],
                    'content' => $from['content'],
                    'viewed' => $online ? 1 : 0,
                ]);

                if ($online) {
                    Gateway::sendToUid($to['id'], $content);
                }
            }

        } elseif ($to['type'] == 'group') {

            $messageModel = new ImGroupMessageModel();

            $messageModel->create([
                'sender_id' => $from['id'],
                'group_id' => $to['id'],
                'content' => $from['content'],
            ]);

            $excludeClientId = null;

            /**
             * 不推送自己在群组中发的消息
             */
            if ($user->id == $from['id']) {
                $excludeClientId = Gateway::getClientIdByUid($user->id);
            }

            $groupName = $this->getGroupName($to['id']);

            Gateway::sendToGroup($groupName, $content, $excludeClientId);
        }
    }

    public function markSystemMessagesAsRead()
    {
        $user = $this->getLoginUser();

        $userRepo = new UserRepo();

        $messages = $userRepo->findUnreadImSystemMessages($user->id);

        if ($messages->count() > 0) {
            foreach ($messages as $message) {
                $message->viewed = 1;
                $message->update();
            }
        }
    }

    public function updateOnline()
    {
        $status = $this->request->getPost('status');

        $user = $this->getLoginUser();

        $online = $status == 'online' ? 1 : 0;

        $user->update(['online' => $online]);

        $this->pushFriendOnlineTips($user, $status);

        return $user;
    }

    public function updateSignature()
    {
        $sign = $this->request->getPost('sign');

        $user = $this->getLoginUser();

        $validator = new UserValidator();

        $validator->checkSign($sign);

        $user->update(['sign' => $sign]);

        return $user;
    }

    public function applyFriend()
    {
        $post = $this->request->getPost();

        $user = $this->getLoginUser();

        $validator = new ImFriendUserValidator();

        $friend = $validator->checkFriend($post['friend_id']);
        $group = $validator->checkGroup($post['group_id']);
        $remark = $validator->checkRemark($post['remark']);

        $validator->checkIfSelfApply($user->id, $friend->id);
        $validator->checkIfJoined($user->id, $friend->id);
        $validator->checkIfBlocked($user->id, $friend->id);

        $this->handleApplyFriendNotice($user, $friend, $group, $remark);
    }

    public function acceptFriend()
    {
        $user = $this->getLoginUser();

        $messageId = $this->request->getPost('message_id');
        $groupId = $this->request->getPost('group_id');

        $validator = new ImFriendUserValidator();

        $validator->checkGroup($groupId);

        $validator = new ImMessageValidator();

        $message = $validator->checkMessage($messageId, 'system');

        if ($message->item_type != ImSystemMessageModel::TYPE_FRIEND_REQUEST) {
            return;
        }

        $userRepo = new UserRepo();

        $sender = $userRepo->findById($message->sender_id);

        $friendUserRepo = new ImFriendUserRepo();

        $friendUser = $friendUserRepo->findFriendUser($user->id, $sender->id);

        if (!$friendUser) {
            $friendUserModel = new ImFriendUserModel();
            $friendUserModel->create([
                'user_id' => $user->id,
                'friend_id' => $sender->id,
                'group_id' => $groupId,
            ]);
        }

        $friendUser = $friendUserRepo->findFriendUser($sender->id, $user->id);

        $groupId = $message->item_info['group']['id'] ?: 0;

        if (!$friendUser) {
            $friendUserModel = new ImFriendUserModel();
            $friendUserModel->create([
                'user_id' => $sender->id,
                'friend_id' => $user->id,
                'group_id' => $groupId,
            ]);
        }

        $itemInfo = $message->item_info;
        $itemInfo['status'] = ImSystemMessageModel::REQUEST_ACCEPTED;
        $message->update(['item_info' => $itemInfo]);

        $this->handleAcceptFriendNotice($user, $sender, $message);
    }

    public function refuseFriend()
    {
        $user = $this->getLoginUser();

        $messageId = $this->request->getPost('message_id');

        $validator = new ImMessageValidator();

        $message = $validator->checkMessage($messageId, 'system');

        if ($message->item_type != ImSystemMessageModel::TYPE_FRIEND_REQUEST) {
            return;
        }

        $itemInfo = $message->item_info;
        $itemInfo['status'] = ImSystemMessageModel::REQUEST_REFUSED;
        $message->update(['item_info' => $itemInfo]);

        $userRepo = new UserRepo();

        $sender = $userRepo->findById($message->sender_id);

        $this->handleRefuseFriendNotice($user, $sender);
    }

    public function applyGroup()
    {
        $post = $this->request->getPost();

        $user = $this->getLoginUser();

        $validator = new ImChatGroupUserValidator();

        $group = $validator->checkGroup($post['group_id']);
        $remark = $validator->checkRemark($post['remark']);

        $validator->checkIfJoined($user->id, $group->id);
        $validator->checkIfBlocked($user->id, $group->id);

        $this->handleApplyGroupNotice($user, $group, $remark);
    }

    public function acceptGroup()
    {
        $user = $this->getLoginUser();

        $messageId = $this->request->getPost('message_id');

        $validator = new ImMessageValidator();

        $message = $validator->checkMessage($messageId, 'system');

        if ($message->item_type != ImSystemMessageModel::TYPE_GROUP_REQUEST) {
            return;
        }

        $groupId = $message->item_info['group']['id'] ?: 0;

        $validator = new ImChatGroupValidator();

        $group = $validator->checkGroup($groupId);

        $validator->checkOwner($user->id, $group->user_id);

        $userRepo = new UserRepo();

        $applicant = $userRepo->findById($message->sender_id);

        $groupUserRepo = new ImChatGroupUserRepo();

        $groupUser = $groupUserRepo->findGroupUser($group->id, $applicant->id);

        if (!$groupUser) {
            $groupUserModel = new ImChatGroupUserModel();
            $groupUserModel->create([
                'group_id' => $group->id,
                'user_id' => $applicant->id,
            ]);
        }

        $itemInfo = $message->item_info;
        $itemInfo['status'] = ImSystemMessageModel::REQUEST_ACCEPTED;
        $message->update(['item_info' => $itemInfo]);

        $this->handleAcceptGroupNotice($user, $applicant, $group);

        $this->handleNewGroupUserNotice($applicant, $group);
    }

    public function refuseGroup()
    {
        $user = $this->getLoginUser();

        $messageId = $this->request->getPost('message_id');

        $validator = new ImMessageValidator();

        $message = $validator->checkMessage($messageId, 'system');

        if ($message->item_type != ImSystemMessageModel::TYPE_GROUP_REQUEST) {
            return;
        }

        $groupId = $message->item_info['group']['id'] ?: 0;

        $validator = new ImChatGroupValidator();

        $group = $validator->checkGroup($groupId);

        $validator->checkOwner($user->id, $group->user_id);

        $itemInfo = $message->item_info;
        $itemInfo['status'] = ImSystemMessageModel::REQUEST_REFUSED;
        $message->update(['item_info' => $itemInfo]);

        $userRepo = new UserRepo();

        $sender = $userRepo->findById($message->sender_id);

        $this->handleRefuseGroupNotice($user, $sender);
    }

    protected function pullUnreadFriendMessages(UserModel $user)
    {
        $userRepo = new UserRepo();

        $messages = $userRepo->findUnreadImFriendMessages($user->id);

        if ($messages->count() == 0) {
            return;
        }

        Gateway::$registerAddress = $this->getRegisterAddress();

        $builder = new ImMessageListBuilder();

        $senders = $builder->getSenders($messages->toArray());

        foreach ($messages as $message) {

            $message->update(['viewed' => 1]);

            $sender = $senders[$message->sender_id];

            $content = kg_json_encode([
                'type' => 'show_chat_msg',
                'message' => [
                    'username' => $sender['name'],
                    'avatar' => $sender['avatar'],
                    'content' => $message->content,
                    'fromid' => $sender['id'],
                    'id' => $sender['id'],
                    'timestamp' => 1000 * $message->create_time,
                    'type' => 'friend',
                    'mine' => false,
                ],
            ]);

            Gateway::sendToUid($user->id, $content);
        }
    }

    protected function pushFriendOnlineTips(UserModel $user, $status)
    {
        /**
         * 检查间隔，避免频繁提醒干扰
         */
        if (time() - $user->update_time < 600) {
            return;
        }

        $userRepo = new UserRepo();

        $friendUsers = $userRepo->findImFriendUsers($user->id);

        if ($friendUsers->count() == 0) {
            return;
        }

        $friendIds = kg_array_column($friendUsers->toArray(), 'friend_id');

        $friends = $userRepo->findByIds($friendIds);

        Gateway::$registerAddress = $this->getRegisterAddress();

        foreach ($friends as $friend) {
            if (Gateway::isUidOnline($friend->id)) {
                $content = kg_json_encode([
                    'type' => 'show_online_tips',
                    'friend' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->avatar,
                    ],
                    'status' => $status == 'online' ? 'online' : 'offline',
                ]);
                Gateway::sendToUid($friend->id, $content);
            }
        }
    }

    protected function handleFriendList(UserModel $user)
    {
        $userRepo = new UserRepo();

        $friendGroups = $userRepo->findImFriendGroups($user->id);
        $friendUsers = $userRepo->findImFriendUsers($user->id);

        $items = [];

        $items[] = [
            'id' => 0,
            'groupname' => '我的好友',
            'list' => [],
        ];

        if ($friendGroups->count() > 0) {
            foreach ($friendGroups as $group) {
                $items[] = [
                    'id' => $group->id,
                    'groupname' => $group->name,
                    'online' => 0,
                    'list' => [],
                ];
            }
        }

        if ($friendUsers->count() == 0) {
            return $items;
        }

        $userIds = kg_array_column($friendUsers->toArray(), 'friend_id');

        $users = $userRepo->findByIds($userIds);

        $userMappings = [];

        foreach ($users as $user) {
            $userMappings[$user->id] = [
                'id' => $user->id,
                'username' => $user->name,
                'avatar' => $user->avatar,
                'sign' => $user->sign,
                'status' => $user->online ? 'online' : 'offline',
            ];
        }

        foreach ($items as $key => $item) {
            foreach ($friendUsers as $friendUser) {
                $userId = $friendUser->friend_id;
                if ($item['id'] == $friendUser->group_id) {
                    $items[$key]['list'][] = $userMappings[$userId];
                } else {
                    $items[0]['list'][] = $userMappings[$userId];
                }
            }
        }

        return $items;
    }

    protected function handleGroupList(UserModel $user)
    {
        $userRepo = new UserRepo();

        $groups = $userRepo->findImChatGroups($user->id);

        if ($groups->count() == 0) {
            return [];
        }

        $baseUrl = kg_ci_base_url();

        $result = [];

        foreach ($groups->toArray() as $group) {
            $group['avatar'] = $baseUrl . $group['avatar'];
            $result[] = [
                'id' => $group['id'],
                'groupname' => $group['name'],
                'avatar' => $group['avatar'],
            ];
        }

        return $result;
    }

    protected function handleChatMessagePager($pager)
    {
        if ($pager->total_items == 0) {
            return $pager;
        }

        $messages = $pager->items->toArray();

        $builder = new ImMessageListBuilder();

        $senders = $builder->getSenders($messages);

        $items = [];

        foreach ($messages as $message) {
            $sender = $senders[$message['sender_id']] ?? new \stdClass();
            $items[] = [
                'id' => $message['id'],
                'content' => $message['content'],
                'timestamp' => $message['create_time'] * 1000,
                'user' => $sender,
            ];
        }

        $pager->items = $items;

        return $pager;
    }

    protected function handleUserPager($pager)
    {
        if ($pager->total_items == 0) {
            return $pager;
        }

        $users = $pager->items->toArray();

        $baseUrl = kg_ci_base_url();

        $items = [];

        foreach ($users as $user) {
            $user['avatar'] = $baseUrl . $user['avatar'];
            $items[] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'avatar' => $user['avatar'],
                'about' => $user['about'],
                'location' => $user['location'],
                'gender' => $user['gender'],
                'vip' => $user['vip'],
                'follower_count' => $user['follower_count'],
                'following_count' => $user['following_count'],
            ];
        }

        $pager->items = $items;

        return $pager;
    }

    protected function handleGroupPager($pager)
    {
        if ($pager->total_items == 0) {
            return $pager;
        }

        $groups = $pager->items->toArray();

        $baseUrl = kg_ci_base_url();

        $items = [];

        foreach ($groups as $group) {
            $group['avatar'] = $baseUrl . $group['avatar'];
            $items[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'avatar' => $group['avatar'],
                'about' => $group['about'],
                'user_count' => $group['user_count'],
            ];
        }

        $pager->items = $items;

        return $pager;
    }

    protected function handleApplyFriendNotice(UserModel $sender, UserModel $receiver, ImFriendGroupModel $group, $remark)
    {
        $userRepo = new UserRepo();

        $itemType = ImSystemMessageModel::TYPE_FRIEND_REQUEST;

        $message = $userRepo->findImSystemMessage($receiver->id, $itemType);

        if ($message) {
            $expired = time() - $message->create_time > 7 * 86400;
            $pending = $message->item_info['status'] == ImSystemMessageModel::REQUEST_PENDING;
            if (!$expired && $pending) {
                return;
            }
        }

        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_FRIEND_REQUEST;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'remark' => $remark,
            'status' => ImSystemMessageModel::REQUEST_PENDING,
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        $online = Gateway::isUidOnline($receiver->id);

        if ($online) {
            $content = kg_json_encode(['type' => 'refresh_msg_box']);
            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleAcceptFriendNotice(UserModel $sender, UserModel $receiver, ImSystemMessageModel $applyMessage)
    {
        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_FRIEND_ACCEPTED;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ]
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        $online = Gateway::isUidOnline($receiver->id);

        if ($online) {

            /**
             * 上层操作更新了item_info，类型发生了变化，故重新获取
             */
            $messageRepo = new ImSystemMessageRepo();
            $message = $messageRepo->findById($applyMessage->id);
            $itemInfo = $message->item_info;

            $content = kg_json_encode([
                'type' => 'friend_accepted',
                'friend' => [
                    'id' => $sender->id,
                    'name' => $sender->name,
                    'avatar' => $sender->avatar,
                ],
                'group' => [
                    'id' => $itemInfo['group']['id'],
                    'name' => $itemInfo['group']['name'],
                ],
            ]);

            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleRefuseFriendNotice(UserModel $sender, UserModel $receiver)
    {
        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_FRIEND_REFUSED;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ]
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        $online = Gateway::isUidOnline($receiver->id);

        if ($online) {
            $content = kg_json_encode(['type' => 'refresh_msg_box']);
            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleApplyGroupNotice(UserModel $sender, ImChatGroupModel $group, $remark)
    {
        $userRepo = new UserRepo();

        $receiver = $userRepo->findById($group->user_id);

        $itemType = ImSystemMessageModel::TYPE_GROUP_REQUEST;

        $message = $userRepo->findImSystemMessage($receiver->id, $itemType);

        if ($message) {
            $expired = time() - $message->create_time > 7 * 86400;
            $pending = $message->item_info['status'] == ImSystemMessageModel::REQUEST_PENDING;
            if (!$expired && $pending) {
                return;
            }
        }

        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_GROUP_REQUEST;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'remark' => $remark,
            'status' => ImSystemMessageModel::REQUEST_PENDING,
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        $online = Gateway::isUidOnline($receiver->id);

        if ($online) {
            $content = kg_json_encode(['type' => 'refresh_msg_box']);
            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleAcceptGroupNotice(UserModel $sender, UserModel $receiver, ImChatGroupModel $group)
    {
        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_GROUP_ACCEPTED;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ]
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        $online = Gateway::isUidOnline($receiver->id);

        if ($online) {

            $content = kg_json_encode([
                'type' => 'group_accepted',
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'avatar' => $group->avatar,
                ],
            ]);

            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleRefuseGroupNotice(UserModel $sender, UserModel $receiver)
    {
        $sysMsgModel = new ImSystemMessageModel();

        $sysMsgModel->sender_id = $sender->id;
        $sysMsgModel->receiver_id = $receiver->id;
        $sysMsgModel->item_type = ImSystemMessageModel::TYPE_GROUP_REFUSED;
        $sysMsgModel->item_info = [
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar' => $sender->avatar,
            ]
        ];

        $sysMsgModel->create();

        Gateway::$registerAddress = $this->getRegisterAddress();

        if (Gateway::isUidOnline($receiver->id)) {
            $content = kg_json_encode(['type' => 'refresh_msg_box']);
            Gateway::sendToUid($receiver->id, $content);
        }
    }

    protected function handleNewGroupUserNotice(UserModel $newUser, ImChatGroupModel $group)
    {
        $groupRepo = new ImChatGroupRepo();

        $users = $groupRepo->findGroupUsers($group->id);

        if ($users->count() == 0) {
            return;
        }

        Gateway::$registerAddress = $this->getRegisterAddress();

        foreach ($users as $user) {
            $content = kg_json_encode([
                'type' => 'new_group_user',
                'user' => [
                    'id' => $newUser->id,
                    'name' => $newUser->name,
                    'avatar' => $newUser->avatar,
                ],
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'avatar' => $group->avatar,
                ],
            ]);
            if (Gateway::isUidOnline($user->id)) {
                Gateway::sendToUid($user->id, $content);
            }
        }
    }

    protected function getGroupName($groupId)
    {
        return "group_{$groupId}";
    }

    protected function getRegisterAddress()
    {
        return '127.0.0.1:1238';
    }

}