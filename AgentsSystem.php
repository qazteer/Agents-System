<?php

namespace app\components;

use app\models\BetSlip;
use Exception;
use linslin\yii2\curl;
use app\components\traits\LogTrait;
use Yii;
use yii\base\InvalidConfigException;
use app\components\ScheduleHelper;
use yii\web\Application;

class AgentsSystem
{
    use LogTrait;
    /*
     * Connect
     * GetBetsReport
     * GetActivePlayers
     * GetBlockedPlayers
     * GetAgentInfo
     * AgentTree
     * AgentSubTree
     *
     * CreateAgent
     * CreateUser
     * TransferMoneyCompareTo
     * TransferMoneyToAllActivePlayers
     * TransferMoneyToUser
     *
     * TransactionReportPlayer
     * TransactionReportAgent
     *
     * GetProductReport-Players
     * getProductReportUsers
     *
     * */

    /**
     * Outbound call "Connect"
     *
     * @return null|mixed
     */
    public static function getConnect()
    {
        $username = self::getApiUsername();

        if (empty($username) || !array_key_exists($username, Yii::$app->params['as_user'])) {
            return [
                'error' => 'Invalid super agent username. Please, try again later or ask administrator.',
            ];
        }

        $password = Yii::$app->params['as_user'][$username]['password'];
        $baseUrl  = Yii::$app->params['as_base_url'];
        $api_host  = Yii::$app->params['api_host'];
        $request = (new curl\Curl())->setHeaders([
            'Content-Type' => 'application/json',
        ]);
        try {
            $response = $request->get($baseUrl.'/user/'.$api_host.'/connect/'.$username.'/'.$password);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return null;
        }

        return $response;
    }

    /**
     * @return string
     */
    private static function getApiUsername()
    {
        return CurrentUserHelper::getSuperUserName();
    }

    /**
     * @return array|bool|mixed
     */
    public static function getBetsByDateRange()
    {
        $dateRange = ScheduleHelper::getDataRangeFromConsole();
        return (!empty($dateRange)) ? self::getBetsReport($dateRange['from'], $dateRange['to']) : false;
    }

    /**
     * @param bool $new
     * @return array|mixed
     * @throws InvalidConfigException
     */
    public static function getBets($new = true)
    {
        if ($new) {
            $bet = BetSlip::find()->orderBy(['date' => SORT_DESC])->one();
            return self::getBetsReport($bet->date);
        }

        $bets = BetSlip::find()->select('bet_id')->where(['status' => Constants::BET_ACCEPTED])->all();

        if (empty($bets)) {
            return false;
        }

        $bets_id = [];
        foreach ($bets as $bet) {
            $bets = [
                'betId' => $bet->bet_id
            ];
            array_push($bets_id, $bets);
        }

        return self::getBetReportByBetId($bets_id);
    }

    /**
     * Outbound call "GetBetsReport"
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return array|mixed
     */
    public static function getBetsReport($dateFrom = null, $dateTo = null)
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from = (!empty($dateFrom)) ? date("m/d/Y H:i:s.u", strtotime($dateFrom)) : Yii::$app->params['project_start_date'];
        $to = (!empty($dateTo)) ? date("m/d/Y H:i:s.u", strtotime($dateTo) + 86399) : date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['bets_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'date' => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $from.' and '.$to,
                ],
            ],
            'sort'   => [
                'status' => 'asc',
                'title'  => 'date'
            ],
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/report/'.$api_host.'/GetBetsReport/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetBetsReport"
     *
     * @param array $bet_ids
     * @return array|mixed
     */
    public static function getBetReportByBetId($bet_ids)
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = $bet_ids;

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/report/'.$api_host.'/GetBetReportByBetId/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        $bets = json_decode($response, true);

        if (empty($bets)) {
            return false;
        }

        $bets_odds = [];
        foreach ($bets as $bet) {
            $odd = self::getBetsOdds($bet['record']['betId']);

            if (empty($odd)) {
                continue;
            }

            $bet['records'] = $odd;
            array_push($bets_odds, $bet);
        }

        return $bets_odds;
    }

    /**
     * Outbound call "GetBetsOdds"
     *
     * @param int $bet_id
     * @return array|mixed
     */
    public static function getBetsOdds($bet_id)
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params = [];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/report/'.$api_host.'/GetBetsOdds/' . $token . '/' . $bet_id);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetActivePlayers"
     *
     * @return array|mixed
     */
    public static function getActivePlayers()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from  = Yii::$app->params['project_start_date'];
        $to = date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['players_active_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'betType' => [
                    'value' => 1
                ],
                'date' => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $from.' and '.$to,
                ],
            ],
            'sort'   => '',
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl.'/data/'.$api_host.'/GetActivePlayers/'.$token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetBlockedPlayers"
     *
     * @return array|mixed
     */
    public static function getBlockedPlayers()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from  = Yii::$app->params['project_start_date'];
        $to = date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['players_block_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'betType' => [
                    'value' => 1
                ],
                'date' => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $from.' and '.$to,
                ],
            ],
            'sort'   => '',
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl.'/data/'.$api_host.'/GetBlockedPlayers/'.$token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetAgentInfo"
     *
     * @return array|mixed
     */
    public static function getAgentInfo()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'key' => $token,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/user/'.$api_host.'/GetAgentInfo');
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * @param string $show_sub_agent
     * @return array|mixed
     */
    public static function getAgentTree($show_sub_agent = 'false')
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from  = Yii::$app->params['project_start_date'];
        $to = date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['agents_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'betType' => [
                    'value' => 1
                ],
                'date' => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $from.' and '.$to,
                ],
            ],
            'sort'   => '',
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->setOption(
                CURLOPT_TIMEOUT,
                Yii::$app->params['curl_timeout']
            )->post($baseUrl . '/user/'.$api_host.'/AgentTree/'.$token.'/showSubAgent='.$show_sub_agent);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetAgentSubTree"
     *
     * @param  $id
     * @return array|mixed
     */
    public static function getAgentSubTree($id)
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['agents_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'currency' => [
                    'action' => ':',
                    'value' => 'multi',
                    'valueLabel' => 'multi',
                ],
                'parentAffiliateId' => [
                    'action' => ':',
                    'value' => $id,
                ],
            ],
            'sort'   => '',
            'isPlus' => true
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/user/'.$api_host.'/AgentSubTree/'.$token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "CreateAgent"
     *
     * @param  object $user
     * @param  object $profile
     * @param  integer|string $role
     * @return array|mixed
     */
    public static function createAgent($user, $profile, $role)
    {
        $token = self::getConnect();
        $apiHost  = Yii::$app->params['api_host'];
        $baseUrl = Yii::$app->params['as_base_url'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        /**
         * 'M' => 'Mandatory'
         * 'O' => 'Optional'
         */
        $params  = [
            'affiliate' => [
                'name'              => $profile->firstname,     // M
                'email'             => $user->email,            // M
                'secondName'        => $profile->nickname,      // O
                'lastName'          => $profile->lastname,      // M
                'secondLastName'    => $profile->username,      // O
                'username'          => $profile->username,      // M
                'password'          => $user->password,         // M
                'agentRole'         => $role,                   // M
                'parentAffiliateId' => $user->api_parent_id,    // M
                'mainCurrency'      => $profile->currency,      // M
                'termsAndCond'      => true,
            ],
            'isAgent'   => true,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/user/' . $apiHost . '/CreateAgent/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "CreateUser"
     *
     * @param  $user
     * @param  $profile
     * @return array|mixed
     */
    public static function createUser($user, $profile)
    {
        $token = self::getConnect();
        $apiHost  = Yii::$app->params['api_host'];
        $baseUrl = Yii::$app->params['as_base_url'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        /**
         * 'M' => 'Mandatory'
         * 'O' => 'Optional'
         */
        $params  = [
            'firstname'   => '',                    // O
            'middleName'  => '',                    // O
            'lastname'    => '',                    // O
            'login'       => $profile->username,    // M
            'phoneNumber' => '',                    // O
            'email'       => $user->email,          // M
            'ParentId'    => $user->api_parent_id,  // M
            'password'    => $user->password,       // M
            'countryCode' => '',                    // O
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/user/' . $apiHost . '/CreateUser/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "TransferMoneyCompareTo"
     *
     * @return array|mixed
     */
    public static function transferMoneyCompareTo()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'key' => $token,
        ];

        try {
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/data/'.$api_host.'/TransferMoneyCompareTo/3000');
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "TransferMoneyToAllActivePlayers"
     *
     * @return array|mixed
     */
    public static function transferMoneyToAllActivePlayers()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'key' => $token,
        ];

        try{
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/data/'.$api_host.'/TransferMoneyToAllActivePlayers/222');
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "TransferMoneyToUser"
     *
     * @param $playerId
     * @param $amount
     * @param $comment
     * @param $currencyCode
     * @return array|mixed
     */
    public static function transferMoneyToUser($playerId, $amount, $comment, $currencyCode)
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'playerId'     => $playerId,
            'moneyStatus'  => 5,
            'amount'       => $amount,
            'comment'      => $comment,
            'currencyCode' => $currencyCode,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/data/'.$api_host.'/TransferMoneyToUser/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * @param $affiliateId
     * @param $amount
     * @param $comment
     * @param $currencyCode
     * @return array|mixed
     */
    public static function transferMoneyToAgent($affiliateId, $amount, $comment, $currencyCode)
    {
        $token = self::getConnect();

        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'affiliateId'     => $affiliateId,
            'moneyStatus'  => 3,
            'amount'       => $amount,
            'comment'      => $comment,
            'currencyCode' => $currencyCode,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/data/'.$api_host.'/TransferMoneyToAgant/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * @param $affiliateId
     * @param $amount
     * @param $comment
     * @param $currencyCode
     * @return array|mixed
     */
    public static function withdrawFromAgent($affiliateId, $amount, $comment, $currencyCode)
    {
        $token = self::getConnect();

        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'affiliateId'     => $affiliateId,
            'moneyStatus'  => 3,
            'amount'       => -$amount,
            'comment'      => $comment,
            'currencyCode' => $currencyCode,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/data/'.$api_host.'/WithdrawFromAgent/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * @param $playerId
     * @param $amount
     * @param $comment
     * @param $currencyCode
     * @return array|mixed
     */
    public static function withdrawFromPlayer($playerId, $amount, $comment, $currencyCode)
    {
        $token = self::getConnect();

        $api_host  = Yii::$app->params['api_host'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'playerId'     => $playerId,
            'moneyStatus'  => 5,
            'amount'       => -$amount,
            'comment'      => $comment,
            'currencyCode' => $currencyCode,
        ];

        $request = (new curl\Curl())->setRequestBody(json_encode($params))
            ->setHeaders([
                'Content-Type' => 'application/json',
            ]);

        try {
            $response = $request->post($baseUrl . '/data/'.$api_host.'/WithdrawFromPlayer/' . $token);
            self::logger($request);
        } catch (Exception $e) {
            self::logger($e);
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetTransactionReportPlayer"
     *
     * @return array|mixed
     */
    public static function getTransactionReportPlayer()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from  = Yii::$app->params['project_start_date'];
        $to = date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['transactions_players_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'date'       => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $to . ' and ' . $from,
                ]
            ],
            'sort'   => '',
        ];

        try {
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/report/'.$api_host.'/TransactionReportPlayer/' . $token);
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "TransactionReportAgent"
     *
     * @return array|mixed
     */
    public static function getTransactionReportAgent()
    {
        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $from  = Yii::$app->params['project_start_date'];
        $to = date('m/d/Y H:i:s.u');
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['transactions_agents_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];
        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'date'       => [
                    'action'     => 'between',
                    'from'       => $from,
                    'to'         => $to,
                    'valueLabel' => $to . ' and ' . $from,
                ]
            ],
            'sort'   => '',
        ];

        try {
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/report/'.$api_host.'/TransactionReportAgent/' . $token);
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Outbound call "GetProductReport-Players"
     *
     * @return array|mixed
     */
    public static function getProductReportPlayers(){

        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['product_players_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];

        $params  = [
            'start'  => $start,
            'limit'  => $limit,
            'filter' => [
                'sort'       => [
                    'title'     => 'registerDate',
                    'status'       => 'DESC',
                ],
            ]
        ];

        try {
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/report/'.$api_host.'/GetProductReport-Players/' . $token);
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);

    }

    /**
     * Outbound call "GetProductReport-Users"
     *
     * @return array|mixed
     */
    public static function getProductReportUsers($params = false){

        $token = self::getConnect();
        $api_host  = Yii::$app->params['api_host'];
        $start  = Yii::$app->params['start_api_request'];
        $limit  = Yii::$app->params['product_agents_limit'];

        if (!$token) {
            return [
                'error' => 'Could not get token. Please, try again later or ask administrator.',
            ];
        }

        $baseUrl = Yii::$app->params['as_base_url'];

        if(!$params) {
            $params = [
                'start' => $start,
                'limit' => $limit,
                'filter' => [
                    'sort' => [
                        'title' => 'registerDate',
                        'status' => 'DESC',
                    ],
                ],
                'sort' => ''
            ];
        }

        try {
            $response = (new curl\Curl())->setRequestBody(json_encode($params))
                ->setHeaders([
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/report/'.$api_host.'/GetProductReport-Users/' . $token);
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return json_decode($response, true);
    }
}
