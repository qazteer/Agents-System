<?php

namespace app\components;

use app\components\traits\FiltersTrait;
use app\models\AgentCasinoStatistic;
use app\models\BetCasino;
use app\models\User;
use Yii;
use app\models\BetSlip;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * Class Dashboard
 * @package app\components
 */
class Dashboard
{
    use FiltersTrait;

    /**
     * @return float|int
     */
    public static function getAvailableBalance()
    {
        return self::getAvailableBalanceCurrentUser();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getTopPlayers10LossesSport()
    {
        return $this->getTopPlayers10LossesSportTable();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getTopPlayers10LossesCasino()
    {
        return $this->getTopPlayers10LossesCasinoTable();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getSportsBookOverviewLive()
    {
        return $this->getTableSportsBookOverview();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getSportsBookOverviewPreMatch()
    {
        return $this->getTableSportsBookOverview(0);
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getSportsBookOverviewBetType()
    {
        return $this->getTableSportsBookOverviewBetType();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function getCasinoOverview()
    {
        return $this->getTableCasinoOverview();
    }

    /**
     * @return int
     * @throws InvalidConfigException
     */
    public function getPlayersData()
    {
        return $this->playersData();
    }

    /**
     * @return array|null
     * @throws InvalidConfigException
     */
    public function getAgentsData()
    {
        return $this->agentsData();
    }

    /**
     * @return array|null
     * @throws InvalidConfigException
     */
    public function getEarningsData()
    {
        return $this->earningsData();
    }

    /**
     * AvailableBalance
     *
     * @return float|int
     */
    private static function getAvailableBalanceCurrentUser()
    {

        $available_balance = 0;

        if(Yii::$app->user->identity->isMasterAgent() || Yii::$app->user->identity->isAgent()){
            $available_balance = empty(Yii::$app->user->identity->profile) ? 0 : Yii::$app->user->identity->profile->getAvailableBalance();
        }

        return $available_balance;
    }

    /**
     * @return array|ActiveRecord[]
     * @throws InvalidConfigException
     */
    private function getTopPlayers10LossesSportTable()
    {
        $this->dateParams();

        $players = $this->getPlayers()
            ->orderBy(['net_loss' => SORT_ASC])
            ->limit(10)
            ->all();

        return $players;
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    private function getTopPlayers10LossesCasinoTable()
    {
        $this->dateParams();
        /** @var ActiveQuery $query */
        $query = BetCasino::find()->alias('bet_casino')
            ->select(['bet_casino.*'])
            ->joinWith('user player', false)
            ->joinWith(['user.profile profile', 'user.parent parent', 'user.parent.profile parent_profile'], false)
            ->addSelect(['username' => new Expression("profile.username")])
            ->andFilterWhere(['=', '[[player]].[[role]]', Constants::USER_ROLE_PLAYER]);
        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->leftJoin(User::tableName() . ' master', 'master.id = parent.parent_id')
                ->andWhere(['master.parent_id' => Yii::$app->session['id']]);
        }elseif (Yii::$app->user->identity->isMasterAgent()) {
            $query->andWhere(['[[parent]].[[parent_id]]' => Yii::$app->session['id']]);
        }elseif (Yii::$app->user->identity->isAgent()) {
            $query->andWhere(['[[player]].[[parent_id]]' => Yii::$app->session['id']]);
        }
        $from_date = Yii::$app->request->post('from_date') ? Yii::$app->request->post('from_date') : $this->weekly_reset_range[0]['week_start_dt'];
        $to_date = Yii::$app->request->post('to_date') ? Yii::$app->request->post('to_date') : $this->date_to;
        if($from_date) $query->andFilterWhere(['>=', 'bet_casino.date', $from_date]);
        if($to_date) $query->andFilterWhere(['<=', 'bet_casino.date', $to_date]);
        $query->orderBy('net_loss DESC');
        $results = $query->limit(10)->asArray()->all();
        return $results;
    }

    /**
     * @return mixed
     * @throws InvalidConfigException
     */
    private function getPlayers()
    {
        return User::find()
            ->alias('pl')
            ->select([
                '[[pl]].*',
                'status_without_discount' => new Expression("
                        -SUM(
                            CASE
                                WHEN [[psj]].[[status_without_discount]]
                                THEN [[psj]].[[status_without_discount]]
                                    
                                ELSE 0
                            END
                        )
                    "),
                'status_with_discount' => new Expression("
                        -SUM(
                            CASE
                                WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                    AND [[psj]].[[status_without_discount]] < 0
                                    AND [[psj]].[[status_with_discount]]
                                THEN [[psj]].[[status_with_discount]]
                                    
                                ELSE [[psj]].[[status_without_discount]]
                            END
                        )
                    "),
                'discount' => new Expression("
                        SUM(
                            CASE
                                WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                    AND [[psj]].[[status_without_discount]] < 0
                                THEN [[psj]].[[discount]]
                
                                ELSE 0
                            END
                        )
                    "),
                'open_bets_count' => new Expression("
                    SUM(
                        CASE
                            WHEN [[psj]].[[open_bets_count]]
                            THEN [[psj]].[[open_bets_count]]
                
                            ELSE 0
                        END
                    )
                "),
                'discountable_bets_count' => new Expression("SUM([[psj]].[[discountable_bets_count]])"),
                'total_bets_count' => new Expression("
                    SUM(
                        CASE
                            WHEN [[psj]].[[total_bets_count]]
                            THEN [[psj]].[[total_bets_count]]
                
                            ELSE 0
                        END
                    )
                "),
                'agent_profit' => new Expression("SUM([[psj]].[[agent_profit]])"),
                'master_profit' => new Expression("SUM([[psj]].[[master_profit]])"),
                'agent_bank_profit' => new Expression("SUM([[psj]].[[agent_bank_profit]])"),
                'master_bank_profit' => new Expression("SUM([[psj]].[[master_bank_profit]])"),
                'rollover' => new Expression("[[psj]].[[rollover]]"),
                'total_deposit' => new Expression("[[psj]].[[total_deposit]]"),
                'total_withdrawal' => new Expression("[[psj]].[[total_withdrawal]]"),
                'total_win' => new Expression("[[psj]].[[total_win]]"),
                'net_loss' => new Expression("[[psj]].[[total_win]] - [[psj]].[[rollover]]"),
            ])
            ->rightJoin(['psj' => $this->playersSubQuery()], '[[psj]].[[id]] = [[pl]].[[id]]')
            ->groupBy('[[pl]].[[id]]');
    }

    /**
     * @param int $is_live
     * @return array
     * @throws InvalidConfigException
     */
    private function getTableSportsBookOverview($is_live = 1)
    {
        /** @var ActiveQuery $query */
        $query = BetSlip::find()->alias('bet_slip')
            ->leftJoin(User::tableName() . ' AS player', 'player.id = bet_slip.user_id')
            ->leftJoin(User::tableName() . ' AS agent', 'agent.id = player.parent_id')
            ->leftJoin(User::tableName() . ' AS master', 'master.id = agent.parent_id')
            ->leftJoin(User::tableName() . ' AS super', 'super.id = master.parent_id')
            ->andWhere(['=', '[[bet_slip]].[[is_live]]',  $is_live]);

        $query = $this->selectsForBetTable($query);

        $query = $this->filtersForBetTable($query);

        return $query->asArray()->one();
    }

    /**
     * Sports book Overview
     *
     * @return array
     * @throws InvalidConfigException
     */
    private function getTableSportsBookOverviewBetType()
    {
        /** @var ActiveQuery $query */
        $query = BetSlip::find()->alias('bet_slip')
            ->addSelect(['single' => "SUM(
                CASE 
                    WHEN [[bet_slip]].[[bet_type]] = '" . Constants::BET_TYPE_SINGLE . "' 
                    THEN 1 
                    ELSE 0 
                END 
            )"])
            ->addSelect(['multiple' => "SUM(
                CASE 
                    WHEN [[bet_slip]].[[bet_type]] = '" . Constants::BET_TYPE_MULTIPLE . "' 
                    THEN 1 
                    ELSE 0 
                END 
            )"])
            ->addSelect(['system' => "SUM(
                CASE 
                    WHEN [[bet_slip]].[[bet_type]] = '" . Constants::BET_TYPE_SYSTEM . "' 
                    THEN 1 
                    ELSE 0 
                END 
            )"])
            ->leftJoin(User::tableName() . ' AS player', 'player.id = bet_slip.user_id')
            ->leftJoin(User::tableName() . ' AS agent', 'agent.id = player.parent_id')
            ->leftJoin(User::tableName() . ' AS master', 'master.id = agent.parent_id')
            ->leftJoin(User::tableName() . ' AS super', 'super.id = master.parent_id');

        $query = $this->selectsForBetTable($query);

        $query = $this->filtersForBetTable($query);

        return $query->asArray()->one();
    }

    /**
     * @param ActiveQuery $query
     * @return mixed
     */
    public function selectsForBetTable($query)
    {
        $query->addSelect(['turnover' => new Expression('SUM([[bet_slip]].[[total_stake]])')])
            ->addSelect(['winning' => new Expression("SUM(
                    CASE 
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_WON . "' 
                        THEN [[bet_slip]].[[win_amount]] - [[bet_slip]].[[total_stake]] 
                        
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_CASHEDOUT . "' 
                        THEN [[bet_slip]].[[win_amount]] - [[bet_slip]].[[total_stake]]
                        
                        ELSE 0 
                    END
                )")])
            ->addSelect(['open_bets' => new Expression("SUM(
                    CASE 
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_ACCEPTED . "' 
                        THEN [[bet_slip]].[[total_stake]] 
                        ELSE 0 
                        END
                )")])
            ->addSelect(['ggr' => new Expression("SUM(
                    CASE 
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_LOST . "' 
                        THEN [[bet_slip]].[[total_stake]] 
                        ELSE 0 
                        END 
                ) - SUM(
                    CASE 
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_WON . "' 
                        THEN [[bet_slip]].[[win_amount]] - [[bet_slip]].[[total_stake]] 
                        
                        WHEN [[bet_slip]].[[status]] = '" . Constants::BET_CASHEDOUT . "' 
                        THEN [[bet_slip]].[[win_amount]] - [[bet_slip]].[[total_stake]]
                        ELSE 0 
                    END 
                )")])
            ->addSelect(['number_of_bets' => new Expression('COUNT([[bet_slip]].[[id]])')])
            ->addSelect(['avg_bets' => 'CAST(AVG([[bet_slip]].[[total_stake]]) as decimal(20,2))'])
            ->addSelect(['bet_per_player' => 'CAST(COUNT([[bet_slip]].[[id]]) / COUNT(DISTINCT [[bet_slip]].[[user_id]]) AS DECIMAL(20,2))'])
            ->addSelect(['number_of_players' => 'COUNT(DISTINCT [[bet_slip]].[[user_id]])']);

        return $query;
    }

    /**
     * @param ActiveQuery $query
     * @return mixed
     */
    public function filtersForBetTable($query)
    {
        $this->dateParams();

        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->andWhere(['=', 'master.parent_id', Yii::$app->user->identity->id]);
        } elseif (Yii::$app->user->identity->isMasterAgent()) {
            $query->andWhere(['=', 'agent.parent_id', Yii::$app->user->identity->id]);
        } elseif (Yii::$app->user->identity->isAgent()) {
            $query->andWhere(['=', 'player.parent_id',  Yii::$app->user->identity->id]);
        } else {
            $query->andWhere('1=0');
        }

        $query->andFilterWhere(['>=', 'bet_slip.date', $this->date_from]);
        $query->andFilterWhere(['<=', 'bet_slip.date', $this->date_to]);

        return $query;
    }

    /**
     * Casino Overview
     *
     * @return array
     * @throws InvalidConfigException
     */
    private function getTableCasinoOverview()
    {
        $this->dateParams();

        /** @var ActiveQuery $query */
        $query = AgentCasinoStatistic::find()->alias('agent_casino')
            ->addSelect(['live_casino_bet' => new Expression('SUM(live_casino_bet)')])
            ->addSelect(['live_casino_win' => new Expression('SUM(live_casino_win)')])
            ->addSelect(['live_casino_ggr' => new Expression('SUM(live_casino_ggr)')])
            ->addSelect(['casino_bet' => new Expression('SUM(casino_bet)')])
            ->addSelect(['casino_win' => new Expression('SUM(casino_win)')])
            ->addSelect(['casino_ggr' => new Expression('SUM(casino_ggr)')]);

        if (Yii::$app->user->identity->isSuperAgent()) {

            $query->leftJoin(User::tableName() . ' agent', 'agent.id = agent_casino.user_id')
                ->leftJoin(User::tableName() . ' AS master', 'master.id = agent.parent_id')
                ->leftJoin(User::tableName() . ' AS super', 'super.id = master.parent_id')
                ->andWhere(['=', 'super.id', Yii::$app->user->identity->id]);

        } elseif (Yii::$app->user->identity->isMasterAgent()) {

            $query->leftJoin(User::tableName() . ' agent', 'agent.id = agent_casino.user_id')
                ->leftJoin(User::tableName() . ' AS master', 'master.id = agent.parent_id')
                ->andWhere(['=', 'master.id', Yii::$app->user->identity->id]);

        } elseif (Yii::$app->user->identity->isAgent()) {

            $query->leftJoin(User::tableName() . ' agent', 'agent.id = agent_casino.user_id')
                ->andWhere(['=', 'agent.id', Yii::$app->user->identity->id]);

        } else {
            $query->andWhere('1=0');
        }

        $from_date = Yii::$app->request->post('from_date') ? Yii::$app->request->post('from_date') : $this->weekly_reset_range[0]['week_start_dt'];
        $to_date = Yii::$app->request->post('to_date') ? Yii::$app->request->post('to_date') : $this->date_to;

        if($from_date) $query->andFilterWhere(['>=', 'agent_casino.date', $from_date]);
        if($to_date) $query->andFilterWhere(['<=', 'agent_casino.date', $to_date]);

        return $query->asArray()->one();
    }

    /**
     * @return int
     * @throws InvalidConfigException
     */
    private function playersData()
    {
        $this->dateParams();

        return User::find()
            ->alias('ag')
            ->select([
                'new_players' => new Expression("
                    SUM(
                        CASE
                            WHEN [[dpt]].[[created_dt]] >= '" . $this->date_from . "'
                                AND [[dpt]].[[created_dt]] <= '" . $this->date_to . "'
                            THEN 1
                
                            ELSE 0
                        END
                    )
                "),
                'new_agents' => new Expression("
                    SUM(
                        CASE
                            WHEN [[ag]].[[created_dt]] >= '" . $this->date_from . "'
                                AND [[ag]].[[created_dt]] <= '" . $this->date_to . "'
                            THEN 1
                
                            ELSE 0
                        END
                    )
                "),
                'open_bets' => new Expression("SUM(IF([[dpt]].[[open_bets_count]], [[dpt]].[[open_bets_count]], 0))"),
                'total_bets' => new Expression("SUM(IF([[dpt]].[[total_bets_count]], [[dpt]].[[total_bets_count]], 0))"),
                'all_players' => new Expression("COUNT([[dpt]].[[id]])"),
                'active_players' => new Expression("
                    SUM(
                        CASE
                            WHEN [[dpt]].[[rollover]] <> 0
                                OR [[dpt]].[[total_deposit]] <> 0
                                OR [[dpt]].[[total_withdrawal]] <> 0
                            THEN 1
                
                            ELSE 0
                        END
                    )
                "),
            ])
            ->leftJoin(['dpt' => $this->getPlayers()], '[[ag]].[[id]] = [[dpt]].[[parent_id]]')
            ->one();
    }

    /**
     * @return array|null
     * @throws InvalidConfigException
     */
    private function agentsData()
    {
        $this->dateParams();

        if (Yii::$app->user->identity->role == Constants::USER_ROLE_AGENT) {
            return null;
        }

        return User::find()
            ->alias('ag')
            ->select([
                'all_agents' => new Expression("COUNT([[dat]].[[id]])"),
                'active_agents' => new Expression("
                    SUM(
                        CASE
                            WHEN [[dat]].[[rollover]] <> 0
                                OR [[dat]].[[total_deposit]] <> 0
                                OR [[dat]].[[total_withdrawal]] <> 0
                            THEN 1
                
                            ELSE 0
                        END
                    )
                "),
                'new_agents' => new Expression("
                    SUM(
                        CASE
                            WHEN [[ag]].[[created_dt]] >= '" . $this->date_from . "'
                                AND [[ag]].[[created_dt]] <= '" . $this->date_to . "'
                            THEN 1
                
                            ELSE 0
                        END
                    )
                "),
            ])
            ->rightJoin(['dat' => $this->agentsSubQuery()], '[[ag]].[[id]] = [[dat]].[[id]]')
            ->andWhere(['[[ag]].[[role]]' => Constants::USER_ROLE_AGENT])
            ->one();
    }

    /**
     * @return array|null
     * @throws InvalidConfigException
     */
    private function earningsData()
    {
        $this->dateParams();

        /** @var ActiveQuery $query */
        $query = User::find()
            ->alias('user')
            ->addSelect([
                'status_without_discount' => new Expression("-SUM(usj.status_without_discount)"),
                'discount' => new Expression("SUM(usj.discount)"),
                'status_with_discount' => new Expression("-SUM(usj.status_with_discount)"),
            ])
            ->andWhere(['user.id' => Yii::$app->user->identity->id]);

        if (Yii::$app->user->identity->isSuperAgent()) {
            $query->addSelect(['my_profit' => new Expression("-SUM(usj.my_profit)")])
                ->leftJoin(['usj' => $this->mastersSubQuery()], 'user.id = usj.parent_id');
        } elseif (Yii::$app->user->identity->isMasterAgent()) {
            $query->addSelect(['my_profit' => new Expression("-SUM(usj.my_profit)")])
                ->leftJoin(['usj' => $this->agentsSubQuery()], 'user.id = usj.parent_id');
        } elseif (Yii::$app->user->identity->isAgent()) {
            $query->addSelect(['my_profit' => new Expression("-SUM(usj.agent_profit)")])
                ->leftJoin(['usj' => $this->playersSubQuery()], 'user.id = usj.parent_id');
        }

        return $query->one();
    }
}
