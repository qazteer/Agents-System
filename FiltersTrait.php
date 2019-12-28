<?php


namespace app\components\traits;


use app\components\Constants;
use app\models\base\WeeklyReset;
use app\models\BetSlip;
use app\models\search\AgentSearch;
use app\models\search\MasterAgentSearch;
use app\models\search\PlayerSearch;
use app\models\Transaction;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\web\Application;

/**
 * Trait FiltersTrait
 * @package app\components\traits
 */
trait FiltersTrait
{
    /** @var string $date_from */
    public $date_from;
    /** @var string $date_to */
    public $date_to;
    /** @var bool $is_this_week_active */
    public $is_this_week_active;
    /** @var array $weekly_reset_range */
    public $weekly_reset_range;
    /** @var int $number_of_weeks */
    public $number_of_weeks;
    /** @var bool $show_only_active */
    public $show_only_active;

    /**
     * @return array
     */
    public function date()
    {
        $from_date = method_exists(Yii::$app->request,'post') && Yii::$app->request->post('from_date') ? Yii::$app->request->post('from_date') : '';
        $to_date = method_exists(Yii::$app->request,'post') && Yii::$app->request->post('to_date') ? Yii::$app->request->post('to_date') : '';

        $date_from = $from_date ? $from_date : $this->weekly_reset_range[0]['week_start_dt'];
        $date_to = $to_date ? $to_date : date("Y-m-d 23:59:59");

        return [
            'date_from' => $date_from,
            'date_to' => $date_to
        ];
    }

    /**
     * @param ActiveQuery $query
     * @param string $column
     * @return ActiveQuery
     */
    public function scopeDate($query, $column)
    {
        return $query->andWhere(['>=', $column, $this->date_from])
            ->andWhere(['<=', $column, $this->date_to]);
    }

    /**
     * @return ActiveQuery
     * @throws InvalidConfigException
     */
    public function betSlipSubQuery()
    {
        $betSlipSubQuery = BetSlip::find()->alias('bsd')
            ->select([
                '[[bsd]].[[id]]',
                '[[bsd]].[[user_id]]',
                '[[bsd]].[[total_stake]]',
                '[[bsd]].[[status]]',
                '[[bsd]].[[win_amount]]',
                'without_discount' => new Expression(
                    "CASE
                        WHEN [[bsd]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN [[bsd]].[[win_amount]] - [[bsd]].[[total_stake]]

                        ELSE 0
                    END"
                ),
                'with_discount' => new Expression(
                    "CASE
                        WHEN [[bsd]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN ([[bsd]].[[win_amount]] - [[bsd]].[[total_stake]]) * (1 - [[upd]].[[discount_limit]] / 100)

                        ELSE 0
                    END"
                ),
                'discount' => new Expression(
                    "CASE
                        WHEN [[bsd]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN ([[bsd]].[[total_stake]] - [[bsd]].[[win_amount]]) * [[upd]].[[discount_limit]] / 100

                        ELSE 0
                    END"
                ),
                'agent_profit' => new Expression(
                    "CASE
                        WHEN [[bsd]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsd]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN ([[bsd]].[[total_stake]] - [[bsd]].[[win_amount]]) * [[upd]].[[bank]] / 100

                        ELSE 0
                    END"
                ),
            ])
            ->leftJoin('profile upd', '[[upd]].[[user_id]] = [[bsd]].[[user_id]]');
        return $this->scopeDate($betSlipSubQuery, 'date');
    }

    /**
     * Getting weekly resets for time filters
     */
    public function getWeeklyResets()
    {
        try {
            /** @var WeeklyReset $last_reset */
            $last_reset = WeeklyReset::find()->orderBy(['id' => SORT_DESC])->limit(9)->all();
        } catch (InvalidConfigException $e) {
            Yii::$app->session->setFlash('error', Yii::t('app', $e->getMessage()));
        }

        if (!empty($last_reset)) {
            $this->weekly_reset_range = $last_reset;
        } else {
            $this->weekly_reset_range[] = [
                'week_start_dt' => Yii::$app->params['project_start_date'],
                'week_finish_dt' => date("Y-m-d 23:59:59"),
            ];
        }
    }

    /**
     * Applying date to class public parameters
     */
    public function dateParams()
    {
        $this->getWeeklyResets();

        if (!isset($this->is_this_week_active)) {
            /**
             * Check if web calls this function
             * If not, using default values
             */
            if (Yii::$app instanceof Application) {
                $this->is_this_week_active = Yii::$app->request->post('is_this_week_active') !== null
                    ? Yii::$app->request->post('is_this_week_active') : 1;

                $this->number_of_weeks = $this->is_this_week_active == 1 && Yii::$app->request->post('number_of_weeks') !== null
                    ? Yii::$app->request->post('number_of_weeks') : 0;
            } else {
                $this->is_this_week_active = 1;
                $this->number_of_weeks = 0;
            }
        }

        if ($this->is_this_week_active == 1) {
            $this->date_from = $this->weekly_reset_range[$this->number_of_weeks]['week_start_dt']
                ? $this->weekly_reset_range[$this->number_of_weeks]['week_start_dt'] : Yii::$app->params['project_start_date'];
            $this->date_to = $this->weekly_reset_range[$this->number_of_weeks]['week_finish_dt']
                ? $this->weekly_reset_range[$this->number_of_weeks]['week_finish_dt'] : date("Y-m-d 23:59:59");
        } else {
            $this->date_from = Yii::$app->request->post('date_from') !== null
                ? Yii::$app->request->post('date_from')
                : date("Y-m-d 00:00:00", strtotime($this->date_from));

            $this->date_to = Yii::$app->request->post('date_to') !== null
                ? Yii::$app->request->post('date_to')
                : date("Y-m-d 23:59:59", strtotime($this->date_to));
        }
    }

    /**
     * @return string
     */
    public function statusFilterSubQuery()
    {
        return "CAST(SUM(
                CASE
                    WHEN [[bsj]].[[without_discount]]
                    THEN [[bsj]].[[without_discount]]
                    
                    ELSE 0
                END
                ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
            )";
    }

    /**
     * @return string
     */
    public function statusWithDiscount()
    {
        return "CAST(SUM([[bsj]].[[with_discount]]) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . "))";
    }

    /**
     * @return string
     */
    public function discountableBetsCount()
    {
        return "SUM(
                    CASE
                        WHEN [[bsj]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsj]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsj]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN 1

                        ELSE 0
                    END
                )";
    }

    /**
     * @return string
     */
    public function userProfit()
    {
        return "CASE 
                    WHEN " . $this->statusFilterSubQuery() . " < 0 AND " . $this->discountableBetsCount() . " >= [[ppt]].[[bet_limit]]
                    THEN " . $this->statusWithDiscount() . " 
                    ELSE " . $this->statusFilterSubQuery() . " 
                    END";
    }

    /**
     * @param array $data
     * @return float
     */
    public static function getTotal($data)
    {
        $total = 0;

        foreach ($data as $item) {
            $total += str_replace(Yii::$app->params['number_format']['thousands_sep'], "", strip_tags($item));
        }

        return $total;
    }

    /**
     * @return ActiveQuery
     * @throws InvalidConfigException
     */
    public function playersSubQuery()
    {
        /** @var ActiveQuery $query */
        $query = PlayerSearch::find()->alias('player')
            ->select([
                'player.*'
            ])
            ->addSelect(['bet_limit' => new Expression("[[ppt]].[[bet_limit]]")])
            ->addSelect(['balance' => new Expression("CAST(([[ppt]].[[available_balance]]) / 100 as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['status_without_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[bsj]].[[without_discount]]
                            THEN [[bsj]].[[without_discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['status_with_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[bsj]].[[with_discount]]
                            THEN [[bsj]].[[with_discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['rollover' => new Expression(
                "SUM(
                    CASE
                        WHEN [[bsj]].[[total_stake]]
                        THEN [[bsj]].[[total_stake]]
                        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['discountable_bets_count' => new Expression(
                "SUM(
                    CASE
                        WHEN [[bsj]].[[status]] = '" . Constants::BET_WON . "'
                            OR [[bsj]].[[status]] = '" . Constants::BET_LOST . "'
                            OR [[bsj]].[[status]] = '" . Constants::BET_CASHEDOUT . "'
                        THEN 1

                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['total_bets_count' => new Expression(
                "COUNT([[bsj]].[[id]])"
            )])
            ->addSelect(['open_bets_count' => new Expression(
                "SUM(
                    CASE
                        WHEN [[bsj]].[[status]] = '" . Constants::BET_ACCEPTED . "'
                        THEN 1

                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['open_bets_sum' => new Expression(
                "SUM(
                    CASE
                        WHEN [[bsj]].[[status]] = '" . Constants::BET_ACCEPTED . "'
                        THEN [[bsj]].[[total_stake]]

                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[bsj]].[[discount]]
                            THEN [[bsj]].[[discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['total_win' => new Expression(
                "SUM(
                    CASE
                        WHEN [[bsj]].[[status]] <> '" . Constants::BET_ACCEPTED . "'
                        THEN [[bsj]].[[win_amount]]
                        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['player_discount' => new Expression('[[ppt]].[[discount_limit]]')])
            ->addSelect(['profit' => new Expression("CAST(" . $this->userProfit() . " as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['agent_profit' => new Expression("CAST(" . $this->userProfit() . " * [[ppt]].[[bank]] / 100 as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['master_profit' => new Expression("CAST(" . $this->userProfit() . " * ([[mpt]].[[bank]] - [[ppt]].[[bank]]) / 100 as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['agent_bank_profit' => new Expression("CAST(" . $this->userProfit() . " * (1 - [[ppt]].[[bank]] / 100) as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['master_bank_profit' => new Expression("CAST(" . $this->userProfit() . " * (1 - [[mpt]].[[bank]] / 100) as decimal(20," . Yii::$app->params['number_format']['decimals'] . "))")])
            ->addSelect(['is_logged_in' => new Expression(
                "CASE
                    WHEN UNIX_TIMESTAMP([[player]].[[last_active_dt]]) + 60 > UNIX_TIMESTAMP()
                    THEN 1
                    ELSE 0
                END"
            )])
            ->addSelect(['total_deposit' => new Expression(
                "CAST(
                    CASE
                        WHEN [[dsj]].[[deposit]]
                        THEN [[dsj]].[[deposit]]

                        ELSE 0
                    END as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['total_withdrawal' => new Expression(
                "CAST(
                    CASE
                        WHEN [[wsj]].[[withdrawal]]
                        THEN [[wsj]].[[withdrawal]]

                        ELSE 0
                    END as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['credits' => new Expression(
                "CAST(
                    CASE
                        WHEN [[dsj]].[[deposit]] AND [[wsj]].[[withdrawal]]
                        THEN [[dsj]].[[deposit]] - [[wsj]].[[withdrawal]]
                        
                        WHEN [[dsj]].[[deposit]]
                        THEN [[dsj]].[[deposit]] - 0
                        
                        WHEN [[wsj]].[[withdrawal]]
                        THEN 0 - [[wsj]].[[withdrawal]]

                        ELSE 0
                    END as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['debt' => new Expression("[[ppt]].[[debt]]")])
            ->where(['[[player]].[[role]]' => Constants::USER_ROLE_PLAYER])
            /** bsj => BetSlip Sub Join */
            ->leftJoin(['bsj' => $this->betSlipSubQuery()], '[[bsj]].[[user_id]] = [[player]].[[id]]')
            /** ppt => Player Profile Table */
            ->leftJoin('profile ppt', '[[ppt]].[[user_id]] = [[player]].[[id]]')
            /** pat => Player Agent Table */
            ->leftJoin('user pat', '[[pat]].[[id]] = [[player]].[[parent_id]]')
            /** apt => Agent Profile Table */
            ->leftJoin('profile apt', '[[apt]].[[user_id]] = [[pat]].[[id]]')
            /** pmt => Player Master Table */
            ->leftJoin('user pmt', '[[pmt]].[[id]] = [[pat]].[[parent_id]]')
            /** mpt => Master Profile Table */
            ->leftJoin('profile mpt', '[[mpt]].[[user_id]] = [[pmt]].[[id]]')
            /** pst => Player Super Table */
            ->leftJoin('user pst', '[[pst]].[[id]] = [[pmt]].[[parent_id]]')
            /** dsj => Deposit Sub Join */
            ->leftJoin(['dsj' => $this->playerDepositSubQuery()], '[[dsj]].[[receiver_user_id]] = [[player]].[[id]]')
            /** wsj => Withdrawal Sub Join */
            ->leftJoin(['wsj' => $this->playerWithdrawalSubQuery()], '[[wsj]].[[sender_user_id]] = [[player]].[[id]]')
            ->groupBy('[[player]].[[id]]');

        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->andWhere(['[[pmt]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        if (Yii::$app->user->identity->isMasterAgent()) {
            $query->andWhere(['[[pat]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        if (Yii::$app->user->identity->isAgent()) {
            $query->andWhere(['[[player]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        return $query;
    }

    /**
     * @return ActiveQuery
     * @throws InvalidConfigException
     */
    public function playerDepositSubQuery()
    {
        /** @var ActiveQuery $query */
        $query = Transaction::find()
            ->alias('dsq')
            ->select(['dsq.receiver_user_id'])
            ->addSelect(['deposit' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[dsq]].[[type]] = '" . Constants::TRANSACTION_TYPE_DEPOSIT . "'
                            THEN [[dsq]].[[amount]]

                            ELSE 0
                        END
                    ) / 100 as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
        ->groupBy('dsq.receiver_user_id');

        if ($this->is_this_week_active || $this->is_this_week_active == null) {
            $query->andWhere(['[[dsq]].[[deposit_type]]' => Constants::TRANSACTION_DEPOSIT_TYPE_ADDITIONAL]);
        }

        return $this->scopeDate($query, 'created_dt');
    }

    /**
     * @return ActiveQuery
     * @throws InvalidConfigException
     */
    public function playerWithdrawalSubQuery()
    {
        /** @var ActiveQuery $query */
        $query = Transaction::find()
            ->alias('wsq')
            ->select(['wsq.sender_user_id'])
            ->addSelect(['withdrawal' =>
                new Expression(
                    "CAST(
                        SUM(
                            CASE
                                WHEN [[wsq]].[[type]] = '" . Constants::TRANSACTION_TYPE_CASHOUT . "'
                                THEN [[wsq]].[[amount]]
                            
                                ELSE 0
                            END
                        ) / 100 as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                    )"
                )
            ])
            ->groupBy('wsq.sender_user_id');

        if ($this->is_this_week_active == null && Yii::$app->request->post('is_this_week_active') !== null) {
            $this->is_this_week_active = Yii::$app->request->post('is_this_week_active');
        }
        if ($this->is_this_week_active || $this->is_this_week_active == null) {
            $query->andWhere(['[[wsq]].[[deposit_type]]' => Constants::TRANSACTION_DEPOSIT_TYPE_ADDITIONAL]);
        }

        return $this->scopeDate($query, 'created_dt');
    }

    /**
     * @return mixed
     * @throws InvalidConfigException
     */
    public function agentsSubQuery()
    {
        switch (Yii::$app->user->identity->role) {
            case Constants::USER_ROLE_SUPER_AGENT:
                $my_profit = ['my_profit' => new Expression("SUM([[psj]].[[master_bank_profit]])")];
                break;

            case Constants::USER_ROLE_MASTER_AGENT:
                $my_profit = ['my_profit' => new Expression("SUM([[psj]].[[master_profit]])")];
                break;

            default:
                $my_profit = ['my_profit' => 0];
        }

        /** @var ActiveQuery $query */
        $query =  AgentSearch::find()->alias('agent')
            ->select(['[[agent]].*'])
            ->addSelect(['status_with_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                AND [[psj]].[[status_without_discount]] < 0
                                AND [[psj]].[[status_with_discount]]
                            THEN [[psj]].[[status_with_discount]]
                            
                            ELSE [[psj]].[[status_without_discount]]
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['status_without_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[psj]].[[status_without_discount]]
                            THEN [[psj]].[[status_without_discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                AND [[psj]].[[status_without_discount]] < 0
                            THEN [[psj]].[[discount]]
        
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect($my_profit)
            ->addSelect(['is_logged_in' => new Expression("
                CASE
                    WHEN UNIX_TIMESTAMP([[agent]].[[last_active_dt]]) + 60 > UNIX_TIMESTAMP()
                    THEN 1
                    ELSE 0
                END")])
            ->addSelect(['rollover' => new Expression(
                "SUM(
                    CASE
                        WHEN [[psj]].[[rollover]]
                        THEN [[psj]].[[rollover]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['total_deposit' => new Expression(
                "SUM(
                    CASE
                        WHEN [[psj]].[[total_deposit]]
                        THEN [[psj]].[[total_deposit]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['total_withdrawal' => new Expression(
                "SUM(
                    CASE
                        WHEN [[psj]].[[total_withdrawal]]
                        THEN [[psj]].[[total_withdrawal]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['number_of_players' => new Expression(
                "COUNT([[psj]].[[id]])"
            )])
            /** apt => Agent Profile Table */
            ->leftJoin('profile apt', '[[apt]].[[user_id]] = [[agent]].[[id]]')
            /** amt => Agent Master Table */
            ->leftJoin('user amt', '[[amt]].[[id]] = [[agent]].[[parent_id]]')
            /** mpt => Master Profile Table */
            ->leftJoin('profile mpt', '[[mpt]].[[user_id]] = [[amt]].[[id]]')
            ->leftJoin(['psj' => $this->playersSubQuery()], '[[psj]].[[parent_id]] = [[agent]].[[id]]')
            ->where(['[[agent]].[[role]]' => Constants::USER_ROLE_AGENT])
            ->groupBy(['[[agent]].[[id]]']);

        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->andWhere(['[[amt]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        if (Yii::$app->user->identity->isMasterAgent()) {
            $query->andWhere(['[[agent]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        return $query;
    }

    /**
     * @return mixed
     * @throws InvalidConfigException
     */
    public function mastersSubQuery()
    {
        /** @var ActiveQuery $query */
        $query =  MasterAgentSearch::find()->alias('master')
            ->select(['master.*'])
            ->addSelect(['status_with_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[asj]].[[status_with_discount]]
                            THEN [[asj]].[[status_with_discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['status_without_discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[asj]].[[status_without_discount]]
                            THEN [[asj]].[[status_without_discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['discount' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[asj]].[[discount]]
                            THEN [[asj]].[[discount]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['my_profit' => new Expression(
                "CAST(
                    SUM(
                        CASE
                            WHEN [[asj]].[[my_profit]]
                            THEN [[asj]].[[my_profit]]
                            
                            ELSE 0
                        END
                    ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                )"
            )])
            ->addSelect(['is_logged_in' => new Expression("
                CASE
                    WHEN UNIX_TIMESTAMP([[master]].[[last_active_dt]]) + 60 > UNIX_TIMESTAMP()
                    THEN 1
                    ELSE 0
                END")])
            ->addSelect(['rollover' => new Expression(
                "SUM(
                    CASE
                        WHEN [[asj]].[[rollover]]
                        THEN [[asj]].[[rollover]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['total_deposit' => new Expression(
                "SUM(
                    CASE
                        WHEN [[asj]].[[total_deposit]]
                        THEN [[asj]].[[total_deposit]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['total_withdrawal' => new Expression(
                "SUM(
                    CASE
                        WHEN [[asj]].[[total_withdrawal]]
                        THEN [[asj]].[[total_withdrawal]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['number_of_players' => new Expression(
                "SUM(
                    CASE
                        WHEN [[asj]].[[number_of_players]]
                        THEN [[asj]].[[number_of_players]]
        
                        ELSE 0
                    END
                )"
            )])
            ->addSelect(['number_of_agents' => new Expression(
                "COUNT([[asj]].[[id]])"
            )])
            ->where(['[[master]].[[role]]' => Constants::USER_ROLE_MASTER_AGENT])
            /** mpt => Agent Profile Table */
            ->leftJoin('profile mpt', '[[mpt]].[[user_id]] = [[master]].[[id]]')
            ->leftJoin(['asj' => $this->agentsSubQuery()], '[[asj]].[[parent_id]] = [[master]].[[id]]')
            ->groupBy(['[[master]].[[id]]']);

        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->andWhere(['[[master]].[[parent_id]]' => Yii::$app->user->identity->getId()]);
        }

        return $query;
    }

    /**
     * @param ActiveQuery $query
     * @return mixed
     */
    public function filterActiveUsers($query)
    {
        if ($this->show_only_active == null || $this->show_only_active == 1) {
            $query->andHaving(['<>', 'rollover', 0])
                ->orHaving(['<>', 'total_deposit', 0])
                ->orHaving(['<>', 'total_withdrawal', 0]);
        }

        return $query;
    }
}
