<?php


namespace app\components\traits;

use app\components\AgentsSystem;
use app\components\Constants;
use app\components\CurrentUserHelper;
use app\components\Dropdowns;
use app\components\SMS;
use app\models\forms\DepositUserForm;
use app\models\forms\WithdrawUserForm;
use app\models\Profile;
use app\models\Transaction;
use app\models\User;
use Throwable;
use Yii;
use yii\base\Exception;
use yii\db\Exception as DBException;
use yii\db\StaleObjectException;
use yii\web\Response;
use yii\widgets\ActiveForm;

/**
 * Trait UserTrait
 * @package app\components\traits
 */
trait UserTrait
{
    /**
     * @param string $role
     * @return array|bool
     */
    public function createUser($role)
    {
        if ($role == Constants::USER_ROLE_MASTER_AGENT) {
            $parentRole = '';
            $roleName = 'master agent';
        } elseif ($role == Constants::USER_ROLE_AGENT) {
            $parentRole = 'master agent';
            $roleName = 'agent';
        } else {
            $parentRole = 'agent';
            $roleName = 'player';
        }

        $user = new User(['scenario' => Constants::SCENARIO_CREATE]);
        $profile = new Profile();

        $user->role = $role;

        if (!$user->load(Yii::$app->request->post()) || !$profile->load(Yii::$app->request->post())) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Data load failed'));
            return false;
        }

        $profile->total_to_spend = intval($profile->total_to_spend);

        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ActiveForm::validate($profile, $user);
        }

        if (!Yii::$app->request->isPost) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Wrong method. You must use POST!'));
            return false;
        }

        //Stub for passing profile validation, rewriting further
        $profile->user_id = Yii::$app->user->identity->id;

        $profile->bank = intval($profile->bank);
        $profile->discount_limit = intval($profile->discount_limit);
        $profile->bet_limit = intval($profile->bet_limit);
        if ($role != Constants::USER_ROLE_PLAYER) {
            $profile->firstname = $profile->username;
            $profile->lastname = $profile->username;
        }

        if ($role != Constants::USER_ROLE_MASTER_AGENT) {
            $parentUser = User::findIdentity($user->parent_id);
            $parentProfile = Profile::findOne(['user_id' => $user->parent_id]);

            if (!$parentProfile || !$parentUser) {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Please, choose ' . $parentRole .'.'));
                return false;
            }

            if ($profile->total_to_spend < 0 || $profile->total_to_spend > $parentProfile->getTotalToSpend()) {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Total to spend must be between 0 and ' . $parentProfile->getTotalToSpend() . ' for this ' . $parentRole . '!'));
                return false;
            }

            if ($role == Constants::USER_ROLE_AGENT) {
                $totalToSpend = $parentProfile->getAvailableBalance() - $parentProfile->getAdditionalCredit();

                if ($totalToSpend <= 0) {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'Total to spend need to increase ' . ' for this ' . $parentRole . '!'));
                    return false;
                }

                if ($totalToSpend < $profile->total_to_spend) {
                    Yii::$app->session->setFlash(
                        'error',
                        Yii::t('app', 'Total to spend could not be more ' . $totalToSpend . ' for ' . $profile->username . '!')
                    );
                    return false;
                }
            }

            if ($profile->bank < 0 || $profile->bank > $parentProfile->bank) {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Bank % must be between 0% and ' . $parentProfile->bank . '% for this ' . $parentRole . '!'));
                return false;
            }

            if ($profile->discount_limit < 0 || $profile->discount_limit > $parentProfile->discount_limit) {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Discount limit % must be between 0% and ' . $parentProfile->discount_limit . '% for this ' . $parentRole . '!'));
                return false;
            }

            if ($profile->bet_limit < $parentProfile->bet_limit) {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Bet limit must be equal or greater then ' . $parentProfile->bet_limit . ' for this ' . $parentRole . '!'));
                return false;
            }

            $user->api_parent_id = $parentUser->api_id;
            $profile->currency = $parentProfile->currency;
        } else {
            $user->parent_id = Yii::$app->user->identity->id;
            $user->api_parent_id = Yii::$app->user->identity->api_id;
        }

        $user->email = $profile->username . '@agents-blue.com';

        try {
            $user->password_hash = Yii::$app->security->generatePasswordHash($user->password);
        } catch (Exception $e) {
            Yii::$app->session->setFlash('error', Yii::t('app', $e->getMessage()));
            return false;
        }

        if (!$user->validate() || !$profile->validate()) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            $e = ActiveForm::validate($profile, $user);
            Yii::$app->session->setFlash('error', Yii::t('app', key($e) . ' => ' . current($e)[0]));
            return false;
        }

        $response = ($role != Constants::USER_ROLE_PLAYER) ?
            AgentsSystem::createAgent($user, $profile, Dropdowns::getDropdownValue('api_roles', $role)) :
            AgentsSystem::createUser($user, $profile);

        if (!empty($response['error'])) {
            Yii::$app->session->setFlash('error', Yii::t('app', $response['error']));
            return false;
        }

        if (empty($response['result'])) {
            Yii::$app->session->setFlash('error', Yii::t('app', json_encode($response)));
            return false;
        }

        $user->api_id = ($role != Constants::USER_ROLE_PLAYER) ? $response['result'] : $response['result']['records'][0]['playerId'];

        try {
            $user->save();
            $profile->user_id = $user->id;
            $profile->save();

            $makeDeposit = ($user->role != Constants::USER_ROLE_PLAYER) ?
                $this->makeWeeklyDeposit($user->parent_id, $user->getId(), -$profile->total_to_spend, true) : true;

            if ($makeDeposit) {
                $message = Yii::t('app', 'A new %s %s was created under %s %s') . ' "\r\n"' . Yii::t('app', 'Agent') . ' = %s  "\r\n"' . Yii::t('app', 'Discount') . ' = %s ';

                if ($profile->phone && Yii::$app->request->post('send_sms')) {
                    $smsSent = SMS::sendLoginDetails($user->getName(), $user->email, $profile->phone, $user->password);
                    $message = ($smsSent) ? $message . Yii::t('app', 'SMS with login details was sent to admin') : $message;
                }

                Yii::$app->session->setFlash('success-user-create', sprintf($message, Yii::t('app', $roleName), $user->getName(), Yii::t('app', $parentRole), $user->parent->getName(), $user->getBankLimit(), $user->getDiscountLimit()));
                return true;
            } else {
                $user->delete();
                Yii::$app->session->setFlash('error', Yii::t('app', 'Can not create new ' . $roleName));
            }
        } catch (StaleObjectException $e) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'StaleObjectException ' . $e->getMessage()));
        } catch (Throwable $e) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Throwable ' . $e->getMessage()));
        }

        return false;
    }

    /**
     * @param int $id User ID
     * @param bool $ajax
     * @return array|bool
     */
    public function makeDeposit($id, $ajax = true)
    {
        $model = new DepositUserForm();

        $user = User::findOne(['id' => $id]);

        $role = $user->role;

        if (!$user) {
            Yii::$app->session->setFlash('error-deposit', Yii::t('app', 'The requested page does not exist.'));
            return $this->redirect(Yii::$app->request->referrer);
        }

        /** @var User $operator */
        $operator = Yii::$app->user->identity;

        /** @var Profile $profile_receiver */
        $user->profile->scenario = Constants::SCENARIO_ADDITIONAL_CREDIT;
        $profile_receiver = $user->profile;


        $sender = (Yii::$app->user->identity->role == Constants::USER_ROLE_SUPER_AGENT || Yii::$app->user->identity->role == Constants::USER_ROLE_MASTER_AGENT) ?
            $user->parent :
            Yii::$app->user->identity;
        /** @var Profile $profile_sender */
        $sender->profile->scenario = Constants::SCENARIO_ADDITIONAL_CREDIT;
        $profile_sender = $sender->profile;

        if (empty($profile_receiver)) {
            Yii::$app->session->setFlash('error-deposit', sprintf(Yii::t('app', 'Receiver profile does not exist')));
            return $this->redirect(Yii::$app->request->referrer);
        }

        if (empty($profile_sender)) {
            Yii::$app->session->setFlash('error-deposit', sprintf(Yii::t('app', 'Sender profile does not exist')));
            return $this->redirect(Yii::$app->request->referrer);
        }

        if ($ajax && Yii::$app->request->isAjax && $model->load(Yii::$app->request->post())) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ActiveForm::validate($model);
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {

            // total_amount - amount of money sent
            $user_data['total_amount'] = $model->getAmounts();
            $user_data['currency'] = $model->currency;
            $user_data['comment'] = $model->comment;

            if ($user_data['total_amount'] <= 0) {
                return $this->redirect(Yii::$app->request->referrer);
            }

            if ($profile_sender->available_balance < $user_data['total_amount']) {
                Yii::$app->session->setFlash('error-deposit', sprintf(Yii::t('app', 'Insufficient funds')));
                return $this->redirect(Yii::$app->request->referrer);
            }

            if ($role == Constants::USER_ROLE_PLAYER) {
                $response_withdraw = $this->helperWithdrawFromAgent($sender, $user_data);
                $response_deposit = $this->helperTransferMoneyToUser($user, $user_data);
            } else {
                $response_withdraw = ($sender->role == Constants::USER_ROLE_SUPER_AGENT && $user->role == Constants::USER_ROLE_MASTER_AGENT) ?
                    0 :
                    $this->helperWithdrawFromAgent($sender, $user_data);
                $response_deposit = $this->helperTransferMoneyToAgent($user, $user_data);
            }

            if (empty($response_deposit['result']['records'][0]['transactionID'])) {
                Yii::$app->session->setFlash('error-deposit',
                    sprintf(Yii::t('app', 'Back office user\'s %s balance not added using api'), $user->email)
                );
                return $this->redirect(Yii::$app->request->referrer);
            }

            $db_transaction = Yii::$app->db->beginTransaction();

            //Store new balance
            $transaction = new Transaction();
            $transaction->setAttributes($model->attributes);
            $transaction->transaction_id_withdraw = (!empty($response_withdraw['result']['records'][0]['transactionID'])) ?
                $response_withdraw['result']['records'][0]['transactionID'] : 0;
            $transaction->transaction_id_deposit = $response_deposit['result']['records'][0]['transactionID'];
            $transaction->receiver_user_id = $id;
            $transaction->sender_user_id = $sender->id;
            $transaction->operator_user_id = $operator->id;
            $transaction->deposit_type = Constants::TRANSACTION_DEPOSIT_TYPE_ADDITIONAL;
            $transaction->operator_ip = Yii::$app->request->userIP;
            $transaction->amount = $user_data['total_amount'];

            $profile_receiver->available_balance += $user_data['total_amount'];
            $profile_receiver->additional_credit += $user_data['total_amount'];
            $profile_receiver->deposits_received_count++;
            $profile_receiver->deposits_received_amount += $user_data['total_amount'];

            $profile_sender->deposits_sent_count++;
            $profile_sender->deposits_sent_amount += $user_data['total_amount'];
            $profile_sender->available_balance = $profile_sender->available_balance - $user_data['total_amount'];

            if ($transaction->save() && $profile_receiver->save() && $profile_sender->save()) {

                try {
                    $db_transaction->commit();
                } catch (DBException $e) {
                    Yii::$app->session->setFlash('error-deposit', sprintf(Yii::t('app', 'Data validation failed')));
                    return $this->redirect(Yii::$app->request->referrer);
                }

                Yii::$app->session->setFlash('success-deposit',
                    sprintf(Yii::t('app', 'User\'s %s balance was increased by %s'), $user->email, $user_data['total_amount'] / 100));
                return $this->redirect(['index', 'id' => $id]);
            } else {

                $profile_receiver->refresh();
                $profile_sender->refresh();
                $db_transaction->rollBack();
            }
        }

        Yii::$app->session->setFlash('error-deposit', sprintf(Yii::t('app', 'Data validation failed')));
        return $this->redirect(Yii::$app->request->referrer);
    }

    /**
     * @param int $id
     * @return array
     * @throws DBException
     * @throws Exception
     */
    public function makeWithdraw($id)
    {

        $model = new WithdrawUserForm();

        $user = User::findOne(['id' => $id]);

        $role = $user->role;

        if (!$user) {
            throw new Exception('The requested page does not exist.');
        }

        /** @var User $operator */
        $operator = Yii::$app->user->identity;

        /** @var Profile $profile_sender */
        $user->profile->scenario = Constants::SCENARIO_ADDITIONAL_CREDIT;
        $profile_sender = $user->profile;

        $receiver = (Yii::$app->user->identity->role == Constants::USER_ROLE_SUPER_AGENT || Yii::$app->user->identity->role == Constants::USER_ROLE_MASTER_AGENT) ?
            $user->parent :
            Yii::$app->user->identity;
        /** @var Profile $profile_receiver */
        $receiver->profile->scenario = Constants::SCENARIO_ADDITIONAL_CREDIT;
        $profile_receiver = $receiver->profile;

        if (Yii::$app->request->isAjax && $model->load(Yii::$app->request->post())) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ActiveForm::validate($model);
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {

            $user_data['total_amount'] = $model->getAmounts();
            $user_data['currency'] = $model->currency;
            $user_data['comment'] = $model->comment;

            if ($user_data['total_amount'] > $profile_sender->available_balance) {
                Yii::$app->session->setFlash(
                    'error-withdraw',
                    sprintf(Yii::t('app', 'Insufficient funds'))
                );
                return $this->redirect(Yii::$app->request->referrer);
            }

            if ($role == Constants::USER_ROLE_PLAYER) {
                $response_withdraw = $this->helperWithdrawFromPlayer($user, $user_data);
                $response_deposit = $this->helperTransferMoneyToAgent($receiver, $user_data);
            } else {
                $response_withdraw = $this->helperWithdrawFromAgent($user, $user_data);
                $response_deposit = ($receiver->role == Constants::USER_ROLE_SUPER_AGENT && $user->role == Constants::USER_ROLE_MASTER_AGENT) ?
                    0 :
                    $this->helperTransferMoneyToAgent($receiver, $user_data);
            }

            if (empty($response_withdraw['result'])) {
                Yii::$app->session->setFlash(
                    'error-withdraw',
                    sprintf(Yii::t('app', 'Back office Master\'s %s balance was not decreased with api'), $user->email)
                );
                return $this->redirect(Yii::$app->request->referrer);
            }

            $db_transaction = Yii::$app->db->beginTransaction();

            $transaction = new Transaction();
            $transaction->setAttributes($model->attributes);
            $transaction->transaction_id_withdraw = $response_withdraw['result']['records'][0]['transactionID'];
            $transaction->transaction_id_deposit = (!empty($response_deposit['result']['records'][0]['transactionID'])) ?
                $response_withdraw['result']['records'][0]['transactionID'] : 0;
            $transaction->sender_user_id = $id;
            $transaction->receiver_user_id = $receiver->id;
            $transaction->operator_user_id = $operator->id;
            $transaction->type = Constants::TRANSACTION_TYPE_CASHOUT;
            $transaction->deposit_type = Constants::TRANSACTION_DEPOSIT_TYPE_ADDITIONAL;
            $transaction->operator_ip = Yii::$app->request->userIP;

            $profile_sender->available_balance -= $user_data['total_amount'];
            $profile_receiver->available_balance += $user_data['total_amount'];

            $transaction->amount = $user_data['total_amount'];

            $profile_receiver->cashouts_received_count++;
            $profile_receiver->cashouts_received_amount += $user_data['total_amount'];

            $profile_sender->cashouts_sent_count++;
            $profile_sender->cashouts_sent_amount += $user_data['total_amount'];

            if ($transaction->save() && $profile_receiver->save() && $profile_sender->save()) {

                $db_transaction->commit();

                Yii::$app->session->setFlash(
                    'success-withdraw',
                    sprintf(Yii::t('app', 'Master\'s %s balance was decreased by %s'), $user->email, $user_data['total_amount'] / 100)
                );
                return $this->redirect(['index', 'id' => $id]);
            } else {

                $profile_receiver->refresh();
                $profile_sender->refresh();
                $db_transaction->rollBack();
            }
        }

        Yii::$app->session->setFlash('error-withdraw', sprintf(Yii::t('app', 'Data validation failed')));
        return $this->redirect(Yii::$app->request->referrer);
    }

    /**
     * @param User $user_deposit_operator
     * @param array $user_data
     * @return array
     */
    private function helperWithdrawFromPlayer($user_deposit_operator, $user_data)
    {
        $user_deposit_response = $response_deposit = AgentsSystem::withdrawFromPlayer(
            $user_deposit_operator->api_id,
            abs($user_data['total_amount'] / 100),
            $user_data['comment'],
            $user_data['currency']
        );

        return $user_deposit_response;
    }

    /**
     * @param User $user_deposit_operator
     * @param array $user_data
     * @return array
     */
    private function helperTransferMoneyToUser($user_deposit_operator, $user_data)
    {
        $user_deposit_response = $response_deposit = AgentsSystem::transferMoneyToUser(
            $user_deposit_operator->api_id,
            abs($user_data['total_amount'] / 100),
            $user_data['comment'],
            $user_data['currency']
        );

        return $user_deposit_response;
    }

    /**
     * @param User $user_deposit_operator
     * @param array $user_data
     * @return array
     */
    private function helperTransferMoneyToAgent($user_deposit_operator, $user_data)
    {
        $user_deposit_response = AgentsSystem::transferMoneyToAgent(
            $user_deposit_operator->api_id,
            abs($user_data['total_amount'] / 100),
            $user_data['comment'],
            $user_data['currency']
        );

        return $user_deposit_response;
    }

    /**
     * @param User $user_withdraw_operator
     * @param array $user_data
     * @return array
     */
    private function helperWithdrawFromAgent($user_withdraw_operator, $user_data)
    {
        $currency  = empty($user_data['currency']) ? CurrentUserHelper::getCurrency() : $user_data['currency'];
        $user_withdraw_response = AgentsSystem::withdrawFromAgent(
            $user_withdraw_operator->api_id,
            abs($user_data['total_amount'] / 100),
            $user_data['comment'],
            $currency
        );

        return $user_withdraw_response;
    }

    /**
     * @param int $sender_id
     * @param int $receiver_id
     * @param int $amount
     * @param bool $creat_user
     * @return bool|string
     * @throws DBException
     */
    public function makeWeeklyDeposit($sender_id, $receiver_id, $amount, $creat_user = false)
    {
        $sender = User::findOne([
            'id' => $sender_id
        ]);

        $sender->profile->scenario = Constants::SCENARIO_WEEKLY_SETTLEMENTS;

        $receiver = User::findOne([
            'id' => $receiver_id
        ]);

        $receiver->profile->scenario = Constants::SCENARIO_WEEKLY_SETTLEMENTS;

        if (empty($sender) || empty($receiver) || empty($amount)) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Empty params!'));
            return false;
        }


        /** @var Profile $profile_sender */
        $profile_sender = $sender->profile;
        /** @var Profile $profile_receiver */
        $profile_receiver = $receiver->profile;

        $user_data['total_amount'] = $amount;
        $user_data['currency'] = CurrentUserHelper::getCurrency();
        $user_data['comment'] = '';

        $user_withdraw_operator = $sender;
        $user_deposit_operator = $receiver;
        $type = Constants::TRANSACTION_TYPE_DEPOSIT;

        if ($sender->role == Constants::USER_ROLE_PLAYER) {

            $sender_withdraw_response = AgentsSystem::withdrawFromPlayer(
                $user_withdraw_operator->api_id,
                abs($user_data['total_amount'] / 100),
                $user_data['comment'],
                $user_data['currency']
            );

            $receiver_deposit_response = AgentsSystem::transferMoneyToAgent(
                $user_deposit_operator->api_id,
                abs($user_data['total_amount'] / 100),
                $user_data['comment'],
                $user_data['currency']
            );

            $type = Constants::TRANSACTION_TYPE_CASHOUT;

        } elseif ($receiver->role == Constants::USER_ROLE_PLAYER) {

            $sender_withdraw_response = $this->helperWithdrawFromAgent($user_withdraw_operator, $user_data);
            $receiver_deposit_response = $this->helperTransferMoneyToUser($user_deposit_operator, $user_data);

        } else {

            $user_withdraw_operator = ($user_data['total_amount'] > 0) ? $receiver : $sender;
            $user_deposit_operator = ($user_data['total_amount'] > 0) ? $sender : $receiver;

            if ($sender->role != Constants::USER_ROLE_SUPER_AGENT) {
                $sender_withdraw_response = $this->helperWithdrawFromAgent($user_withdraw_operator, $user_data);
            }

            if ($sender->role == Constants::USER_ROLE_SUPER_AGENT && $user_data['total_amount'] > 0) {
                $sender_withdraw_response = $this->helperWithdrawFromAgent($user_withdraw_operator, $user_data);
            } else {
                $receiver_deposit_response = AgentsSystem::transferMoneyToAgent(
                    $user_deposit_operator->api_id,
                    abs($user_data['total_amount'] / 100),
                    $user_data['comment'],
                    $user_data['currency']
                );
            }

            $type = ($user_data['total_amount'] < 0) ? Constants::TRANSACTION_TYPE_DEPOSIT : Constants::TRANSACTION_TYPE_CASHOUT;
        }

        if (empty($sender_withdraw_response['result']['records'][0]['transactionID']) &&
            empty($receiver_deposit_response['result']['records'][0]['transactionID'])) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Empty response!'));
            return false;
        }

        $db_transaction = Yii::$app->db->beginTransaction();

        $transaction = new Transaction();
        $transaction->transaction_id_withdraw = !empty($sender_withdraw_response['result']['records'][0]['transactionID']) ?
            $sender_withdraw_response['result']['records'][0]['transactionID'] : 0;
        $transaction->transaction_id_deposit = !empty($receiver_deposit_response['result']['records'][0]['transactionID']) ?
            $receiver_deposit_response['result']['records'][0]['transactionID'] : 0;
        $transaction->sender_user_id = $user_withdraw_operator->id;
        $transaction->receiver_user_id = $user_deposit_operator->id;
        $transaction->operator_user_id = $user_withdraw_operator->id;
        $transaction->type = $type;
        if (!$creat_user) {
            $transaction->deposit_type = Constants::TRANSACTION_DEPOSIT_TYPE_WEEKLY;
            $transaction->operator_ip = Constants::TRANSACTION_TYPE_SYSTEM;
        } else {
            $transaction->deposit_type = Constants::TRANSACTION_DEPOSIT_TYPE_ADDITIONAL;
            $transaction->operator_ip = method_exists(Yii::$app->request,'userIP') ? Yii::$app->request->userIP : Constants::TRANSACTION_TYPE_SYSTEM;
        }

        $transaction->amount = abs($user_data['total_amount']);

        $profile_sender->available_balance += $user_data['total_amount'];
        $profile_sender->cashouts_sent_count++;
        $profile_sender->cashouts_sent_amount += abs($user_data['total_amount']);

        if (!$creat_user) {
            $profile_sender->additional_credit = 0;
        }

        $profile_sender->discount_status = 0;

        $profile_receiver->available_balance -= $user_data['total_amount'];
        $profile_receiver->cashouts_received_count++;
        $profile_receiver->cashouts_received_amount += abs($user_data['total_amount']);
        $profile_receiver->additional_credit = 0;
        $profile_receiver->discount_status = 0;



        if ($transaction->save() && $profile_receiver->save() && $profile_sender->save()) {

            $db_transaction->commit();

            return Yii::t('app', 'Success withdraw from agent');
        } else {

            $profile_receiver->refresh();
            $profile_sender->refresh();
            $db_transaction->rollBack();
        }

        return Yii::t('app', 'Data validation failed');
    }

    /**
     * @param array $arrayOfObjects
     * @param int $searchedValue
     * @return array
     */
    public function objArraySearch($arrayOfObjects, $searchedValue)
    {
        $neededObject = array_filter(
            $arrayOfObjects,
            function ($e) use ($searchedValue) {
                return $e->user_id == $searchedValue;
            }
        );

        return $neededObject;
    }
}
