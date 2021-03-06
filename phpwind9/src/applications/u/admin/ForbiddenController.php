<?php

Wind::import('ADMIN:library.AdminBaseController');

/**
 * 用户禁止.
 *
 * @author xiaoxia.xu <x_824@sina.com>
 * @copyright ©2003-2103 phpwind.com
 * @license http://www.windframework.com
 *
 * @version $Id: ForbiddenController.php 28859 2013-05-28 03:11:35Z jieyin $
 */
class ForbiddenController extends AdminBaseController
{
    /*
     * 用户禁止设置
     * @see WindController::run()
     */
    public function run()
    {
        $banService = $this->_getService();
        $this->setOutput($this->getInput('value'), 'value');
        $this->setOutput($banService->getBanType(), 'types');
    }

    /**
     * 禁止用户.
     */
    public function dorunAction()
    {
        $this->getRequest()->isPost() || $this->showError('operate.fail');

        $key = $this->getInput('key', 'post');
        if (!in_array($key, ['1', '2'])) {
            $this->showError('USER:ban.error.data.format');
        }
        $array = [];
        list($end_time, $reason, $types) = $this->getInput(['end_time', 'reason', 'type'], 'post');
        $userInfos = $this->_getUids(explode(',', $this->getInput('value', 'post')), intval($key));
        if (!$userInfos) {
            $this->showError('USER:ban.user.illegal');
        }

        //如果是创始人  则自动设置为system
        $_uid = $this->loginUser->uid;
        $_operator = $this->loginUser->username;
        if ($this->isFounder($_operator)) {
            $_operator = 'system';
            $_uid = 0;
        }
        if ($end_time > 0) {
            $end_time = Pw::str2time($end_time);
        }

        $_notice = [];
        $rightTypes = array_keys($this->_getService()->getBanType());
        foreach ($types as $type) {
            if (!in_array($type, $rightTypes)) {
                continue;
            }
            foreach ($userInfos as $uid => $info) {
                $dm = new PwUserBanInfoDm();
                $dm->setUid($uid)
                    ->setEndTime(intval($end_time))
                    ->setTypeid($type)
                    ->setReason($reason)
                    ->setOperator($_operator)
                    ->setCreatedUid($_uid);
                $array[] = $dm;

                isset($_notice[$uid]) || $_notice[$uid] = [];
                $_notice[$uid]['end_time'] = $end_time;
                $_notice[$uid]['reason'] = $reason;
                $_notice[$uid]['type'][] = $type;
                $_notice[$uid]['operator'] = $_operator;
            }
        }
        $r = $this->_getService()->banUser($array);
        if ($r instanceof PwError) {
            $this->showError($r->getError(), 'u/forbidden/run');
        }

        $this->_getService()->sendNotice($_notice, 1);
        $this->showMessage('USER:ban.success', 'u/forbidden/run');
    }

    /**
     * 自动禁止设置.
     */
    public function autoAction()
    {
        $config = Wekit::C()->getValues('site');

        $default = ['autoForbidden.open' => 0, 'autoForbidden.condition' => ['autoForbidden.credit' => 0, 'autoForbidden.num' => 0], 'autoForbidden.day' => 0, 'autoForbidden.type' => [], 'autoForbidden.reason' => ''];
        $this->setOutput(array_merge($default, $config), 'config');

        /* @var $pwCreditBo PwCreditBo */
        $pwCreditBo = PwCreditBo::getInstance();
        $this->setOutput($pwCreditBo, 'creditBo');
        $banService = $this->_getService();
        $this->setOutput($this->_getBanDayType(), 'dayTypes');
        $this->setOutput($banService->getBanType(), 'types');
    }

    /**
     * 设置自动禁止.
     */
    public function dosetautoAction()
    {
        $this->getRequest()->isPost() || $this->showError('operate.fail');

        $config = new PwConfigSet('site');
        list($open, $condition, $type, $reason) = $this->getInput(['open', 'condition', 'type', 'reason'], 'post');
        if ($open == 1) {
            if (!$condition['num']) {
                $this->showError('USER:ban.auto.credit.num.require');
            }
            if (!$type) {
                $this->showError('USER:ban.type.require');
            }
            if (!$reason) {
                $this->showError('USER:ban.reason.require');
            }
        }
        $config->set('autoForbidden.open', $open)
            ->set('autoForbidden.condition', $condition)
            ->set('autoForbidden.day', $this->getInput('day', 'post'))
            ->set('autoForbidden.type', $type)
            ->set('autoForbidden.reason', $reason)
            ->flush();
        $this->showMessage('USER:ban.auto.set.success', 'u/forbidden/auto');
    }

    /**
     * 获得列表.
     */
    public function listAction()
    {
        $page = intval($this->getInput('page'));
        $perpage = 10;
        $page <= 0 && $page = 1;
        $searchSo = new PwUserBanSo();
        $searchSo->setType($this->getInput('key'))
            ->setKeywords($this->getInput('value'))
            ->setCreatedUsername($this->getInput('operator'))
            ->setStartTime($this->getInput('start_time'))
            ->setEndTime($this->getInput('end_time'));
        $result = [];
        /* @var $banDs PwUserBan */
        $banDs = Wekit::load('user.PwUserBan');
        $count = $banDs->countWithCondition($searchSo);
        if ($count > 0) {
            $totalPage = ceil($count / $perpage);
            $page > $totalPage && $page = $totalPage;
            $num = $num <= 0 ? 10 : $num;
            list($start, $limit) = Pw::page2limit($page, $perpage);
            $result = $this->_getService()->searchBanInfo($searchSo, $limit, $start);
        }
        $this->setOutput($result, 'list');
        $this->setOutput($count, 'count');
        $this->setOutput($searchSo, 'searchSo');
        $this->setOutput($page, 'page');
        $this->setOutput($perpage, 'perpage');
        $this->setOutput($searchSo->getArgsUrl(), 'urlArgs');
    }

    /**
     * 解除禁止.
     */
    public function delAction()
    {
        $ids = $this->getInput('ids', 'post');
        if (!$ids) {
            $this->showError('operate.select');
        }
        /* @var $banSrv PwUserBanService */
        $banSrv = Wekit::load('SRV:user.srv.PwUserBanService');
        $r = $banSrv->batchDelete($ids);
        if ($r instanceof PwError) {
            $this->showError($r->getError());
        } else {
            $_operator = $this->loginUser->username;
            if ($this->isFounder($_operator)) {
                $_operator = 'system';
            }

            $_notice = [];
            foreach ($r as $_item) {
                $uid = $_item['uid'];
                isset($_notice[$uid]) || $_notice[$uid] = [];
                $_notice[$uid]['end_time'] = $_item['end_time'];
                $_notice[$uid]['reason'] = $_item['reason'];
                $_notice[$uid]['type'][] = $_item['typeid'];
                $_notice[$uid]['operator'] = $_operator;
            }
            $banSrv->sendNotice($_notice, 2);
        }
        $this->showMessage('USER:ban.delete.success');
    }

    /**
     * 获得禁止的期限.
     *
     * @return array
     */
    private function _getBanDayType()
    {
        static $days = [];
        if (!$days) {
            $days = [
                0   => ['title' => '永久'], //永久禁止
                3   => ['title' => '三天'], //禁止三天
                7   => ['title' => '一周'], //禁止一周
                14  => ['title' => '二周'],  //禁止二周
                30  => ['title' => '一个月'], //禁止一个月
                60  => ['title' => '二个月'], //禁止二个月
                180 => ['title' => '半年'], //禁止半年
                360 => ['title' => '一年'], //禁止一年
            ];
        }

        return $days;
    }

    /**
     * 根据类型获得用户ID=>$name.
     *
     * @param array $values 值
     * @param int   $type   $value的类型是uid(1)还是usename(2)
     *
     * @return bool
     */
    private function _getUids($values, $type = 1)
    {
        /* @var $userDs PwUser */
        $userDs = Wekit::load('user.PwUser');
        $values = !empty($values) ? array_unique($values) : [];
        if (!$values) {
            $this->showError('USER:ban.user.require');
        }
        switch (intval($type)) {
            case 1:
                $infos = $userDs->fetchUserByUid($values, PwUser::FETCH_MAIN);
                break;
            case 2:
                $infos = $userDs->fetchUserByName($values, PwUser::FETCH_MAIN);
                break;
            default:
                return [];
        }

        return $infos;
    }

    /**
     * 返回禁止服务对象
     *
     * @return PwUserBanService
     */
    private function _getService()
    {
        return Wekit::load('SRV:user.srv.PwUserBanService');
    }
}
