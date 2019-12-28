<?php

namespace app\models\search;

use app\components\Constants;
use app\components\CurrentUserHelper;
use app\components\Dropdowns;
use app\components\Formatter;
use app\components\traits\FiltersTrait;
use app\models\ProfileUpdate;
use app\models\User;
use stdClass;
use Yii;
use yii\base\InvalidConfigException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\helpers\Url;

/**
 * PlayerSearch represents the model behind the search form of `app\models\User`.
 */
class PlayerSearch extends User
{
    use FiltersTrait;

    /** Pagination parameter */
    /** @var integer $page_size */
    public $page_size;

    /** Data for table */
    /** @var int $total_bets_count */
    public $total_bets_count;
    /** @var int $discountable_bets_count */
    public $discountable_bets_count;
    /** @var integer $open_bets_count */
    public $open_bets_count;
    /** @var float $open_bets_sum */
    public $open_bets_sum;
    /** @var float $rollover */
    public $rollover;
    /** @var float $status_without_discount */
    public $status_without_discount;
    /** @var float $status_with_discount */
    public $status_with_discount;
    /** @var float $discount */
    public $discount;
    /** @var float $profit */
    public $profit;
    /** @var float $agent_profit */
    public $agent_profit;
    /** @var float $master_profit */
    public $master_profit;
    /** @var float $agent_bank_profit */
    public $agent_bank_profit;
    /** @var float $master_bank_profit */
    public $master_bank_profit;
    /** @var int $total_win */
    public $total_win;
    /** @var int $net_loss */
    public $net_loss;

    /** Custom filters */
    /** @var integer $player_id_filter */
    public $player_id_filter;
    /** @var string $player_username_filter */
    public $player_username_filter;
    /** @var string $agent_username_filter */
    public $agent_username_filter;
    /** @var string $amount_sign */
    public $balance_sign;
    /** @var integer $status_filter */
    public $balance_filter;
    /** @var string $amount_sign */
    public $status_sign;
    /** @var integer $status_filter */
    public $status_filter;

    /** Invisible filters */
    /** @var integer $player_id */
    public $player_id;
    /** @var integer $agent_id */
    public $agent_id;
    /** @var integer $master_id */
    public $master_id;

    /** Properties for related models */
    /** @var integer $total_deposit */
    public $total_deposit;
    /** @var integer $total_withdrawal */
    public $total_withdrawal;
    /** @var integer $credits */
    public $credits;
    /** @var integer $balance */
    public $balance;
    /** @var integer $logged_in */
    public $logged_in;
    /** @var integer $total_debt */
    public $total_debt;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [
                [
                    'player_id_filter',
                    'player_id',
                    'agent_id',
                    'master_id',
                    'balance_filter',
                    'status_filter',
                    'number_of_weeks',
                ],
                'integer',
            ],
            [
                [
                    'player_username_filter',
                    'agent_username_filter',
                    'date_from',
                    'date_to',
                ],
                'string',
            ],
            [[
                'balance_sign',
                'status_sign',
            ], 'in', 'range' => array_keys(Dropdowns::getDropdownList('comparison_signs'))],
            [[
                'is_this_week_active',
                'show_only_active',
            ], 'boolean'],
        ];
    }

    /**
     * @return array
     */
    public static function getYesNo()
    {
        return [
            'yes' => Yii::t('app', 'Yes'),
            'no' => Yii::t('app', 'No'),
        ];
    }

    /**
     * Initialization
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     * @return ActiveDataProvider
     * @throws InvalidConfigException
     */
    public function search($params)
    {
        $this->load($params);

        $this->dateParams();

        /** @var ActiveQuery $query */
        $query = $this->playersSubQuery();

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => [
                'defaultOrder' => ['id' => SORT_ASC],
                'attributes' => [
                    'username' => [
                        'asc' => ['ppt.username' => SORT_ASC],
                        'desc' => ['ppt.username' => SORT_DESC],
                    ],
                    'agent' => [
                        'asc' => ['apt.username' => SORT_ASC],
                        'desc' => ['apt.username' => SORT_DESC],
                    ],
                    'status' => [
                        'asc' => ['status' => SORT_ASC],
                        'desc' => ['status' => SORT_DESC],
                    ],
                    'credits' => [
                        'asc' => ['credits' => SORT_ASC],
                        'desc' => ['credits' => SORT_DESC],
                    ],
                    'rollover' => [
                        'asc' => ['rollover' => SORT_ASC],
                        'desc' => ['rollover' => SORT_DESC],
                    ],
                    'discount' => [
                        'asc' => ['discount' => SORT_ASC],
                        'desc' => ['discount' => SORT_DESC],
                    ],
                    'profit' => [
                        'asc' => ['profit' => SORT_ASC],
                        'desc' => ['profit' => SORT_DESC],
                    ],
                    'agent_profit' => [
                        'asc' => ['agent_profit' => SORT_ASC],
                        'desc' => ['agent_profit' => SORT_DESC],
                    ],
                    'master_profit' => [
                        'asc' => ['master_profit' => SORT_ASC],
                        'desc' => ['master_profit' => SORT_DESC],
                    ],
                    'agent_bank_profit' => [
                        'asc' => ['agent_bank_profit' => SORT_ASC],
                        'desc' => ['agent_bank_profit' => SORT_DESC],
                    ],
                    'master_bank_profit' => [
                        'asc' => ['master_bank_profit' => SORT_ASC],
                        'desc' => ['master_bank_profit' => SORT_DESC],
                    ],
                    'is_logged_in' => [
                        'asc' => ['is_logged_in' => SORT_ASC],
                        'desc' => ['is_logged_in' => SORT_DESC],
                    ],
                    'last_login_ip' => [
                        'asc' => ['ppt.last_login_ip' => SORT_ASC],
                        'desc' => ['ppt.last_login_ip' => SORT_DESC],
                    ],
                    'id',
                    'balance',
                    'status_without_discount',
                    'rollover',
                    'total_bets_count',
                    'open_bets_count',
                    'open_bets_sum',
                    'created_dt',
                    'login_dt'
                ]
            ],
            'pagination' => [
                'pageSize' => $this->page_size ?: Yii::$app->params['default_grid_page_size']
            ],
        ]);

        $query = $this->filters($query);

        if (!$this->validate()) {
            $query->where('0=1');
        }

        return $dataProvider;
    }

    /**
     * @param ActiveQuery $query
     *
     * @return ActiveQuery
     */
    public function filters($query)
    {
        $query->andFilterWhere(['[[player]].[[role]]' => Constants::USER_ROLE_PLAYER]);
        $query->andFilterWhere(['!=', '[[player]].[[id]]', Yii::$app->user->identity->getId()]);
        $query = $this->filterActiveUsers($query);

        /** Custom filters */
        /** Filter by player ID */
        if ($this->player_id_filter != null) {
            $query->andWhere(['[[player]].[[id]]' => $this->player_id_filter]);
        }

        /** Filter by player username */
        if ($this->player_username_filter != null) {
            $query->andWhere(['like', '[[ppt]].[[username]]', $this->player_username_filter]);
        }

        /** Filter by agent username */
        if ($this->agent_username_filter != null) {
            $query->andWhere(['like', '[[apt]].[[username]]', $this->agent_username_filter]);
        }

        /** Filter by balance */
        if ($this->balance_filter != null) {
            $sign = Dropdowns::getDropdownText('comparison_signs', $this->balance_sign);
            $query->andHaving([$sign, '[[balance]]', $this->balance_filter]);
        }

        /** Filter by status */
        if ($this->status_filter != null) {
            $sign = Dropdowns::getDropdownText('comparison_signs', $this->status_sign);
            $query->andHaving([$sign, '[[status_without_discount]]', $this->status_filter]);
        }

        /** Invisible filters */
        /** Filter by player ID */
        if ($this->player_id != null) {
            $query->andWhere(['[[player]].[[id]]' => $this->player_id]);
        }

        /** Filter by agent ID */
        if ($this->agent_id != null) {
            $query->andWhere(['[[player]].[[parent_id]]' => $this->agent_id]);
        }

        /** Filter by master ID */
        if ($this->master_id != null) {
            $query->andWhere(['[[pat]].[[parent_id]]' => $this->master_id]);
        }

        return $query;
    }

    /**
     * @return string
     */
    public function formName()
    {
        return '';
    }

    /**
     * @return array
     */
    public function getColumns()
    {
        return [
            [
                'class' => 'kartik\grid\CheckboxColumn',
            ],
            [
                'label' => Yii::t('app', 'UID'),
                'attribute' => 'id',
                'format' => 'raw',
                'headerOptions' => ['class' => 'max-70'],
                'value' => function (User $model) {
                    $classHighlighted = strtotime($this->weekly_reset_range[0]['week_start_dt']) < strtotime($model->created_dt) ? 'blue_highlight' : '';
                    return "<span class='{$classHighlighted}' onclick='copyToClipboard(this)' title='" . Yii::t('app', 'UID') . "'>{$model->getId()}</span>";
                }
            ],
            [
                'label' => Yii::t('app', 'Username'),
                'attribute' => 'username',
                'format' => 'raw',
                'value' => function (User $model) {
                    $nickname = $model->profile->nickname ? $model->profile->nickname : $model->profile->username;
                    $admin = Yii::$app->user->identity->isSuperAgent() ? 1 : 0;
                    $super = Yii::$app->user->identity->isMasterAgent() ? 1 : 0;
                    $playerDiscount = $model->getDiscountLimit();
                    /** @var  ProfileUpdate $updatedAgentProfile */
                    $updatedAgentProfile = ProfileUpdate::getUpdateThisWeek($model->parent->getId());
                    $agentDiscount = (!empty($updatedAgentProfile)) ? $updatedAgentProfile->discount_limit : $model->parent->getDiscountLimit();
                    $agentPercent = $model->getBankLimit();
                    $masterTotalPercent = $model->getUserGrandParent()->getBankLimit();
                    $totalMoney = Formatter::formatNumberDisplay($model->profile->additional_credit / 100);
                    $valueSuper = 100 - $masterTotalPercent;
                    $valueMaster = $masterTotalPercent - $agentPercent;
                    $betLimit = $model->profile->bet_limit;
                    $agentBetLimit = $model->getUserParent()->getBetLimit();
                    /** @var  ProfileUpdate $updatedPlayerProfile */
                    $updatedPlayerProfile = ProfileUpdate::getUpdateThisWeek($model->getId());
                    $newDiscount = (!empty($updatedPlayerProfile)) ? $updatedPlayerProfile->discount_limit : $playerDiscount;
                    $newAgentRevenue = (!empty($updatedPlayerProfile)) ? $updatedPlayerProfile->bank : $agentPercent;
                    $updatedSuperProfile = ProfileUpdate::getUpdateThisWeek($model->getUserGrandParent()->getId());
                    $newMasterRevenue = (!empty($updatedSuperProfile)) ? $updatedSuperProfile->bank - $newAgentRevenue : $valueMaster;
                    $newSuperRevenue = (!empty($updatedSuperProfile)) ? 100 - $updatedSuperProfile->bank : $valueSuper;
                    $newBetLimit = (!empty($updatedPlayerProfile)) ? $updatedPlayerProfile->bet_limit : $betLimit;
                    $dataWhatever = $model->profile->username . ';' .   // Username
                        $model->profile->nickname . ';' .               // Nickname
                        $playerDiscount . ';' .                         // Discount limit
                        $model->status . ';' .                          // Status
                        $totalMoney . ';' .                             // Total balance
                        $valueSuper . ';' .                             // Super agent revenue
                        $valueMaster . ';' .                            // Master agent revenue
                        $agentPercent . ';' .                           // Agent revenue
                        $model->getUserParent()->getBankLimit() . ';' . // Agent limit
                        $newDiscount . ';' .                            // New discount limit
                        $newSuperRevenue . ';' .                        // New super agent revenue
                        $newMasterRevenue . ';' .                       // New master agent revenue
                        $newAgentRevenue . ';' .                        // New agent revenue
                        $agentDiscount . ';' .                          // Agent Discount limit
                        $betLimit . ';' .                               // Bet limit
                        $newBetLimit . ';' .                            // New bet limit
                        $agentBetLimit;                                 // Master total money to spend

                    return "<a id='{$model->getId()}' data-toggle='modal' class='table-username underline'
                                title='{$nickname}'
                                onclick='pullData({$model->getId()}, {$admin}, {$super})'
                                data-whatever='{$dataWhatever}'
                                data-target='#userDetail' style='cursor: pointer;'>{$model->profile->username}</a>";
                }
            ],
            [
                'label' => Yii::t('app', 'Agent'),
                'attribute' => 'agent',
                'format' => 'raw',
                'value' => function (PlayerSearch $model) {
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Agent') . "'>{$model->parent->profile->username}</span>";
                }
            ],
            [
                'label' => Yii::t('app', 'Active'),
                'attribute' => 'status',
                'format' => 'raw',
                'value' => function (User $model) {
                    $class = ($model->status === Constants::STATUS_ENABLED) ? 'label-success' : 'label-default';
                    $value = Dropdowns::getDropdownText('statuses', $model->status);
                    return "<span onclick='changeUserStatus({$model->id}, this, \"player\", \"{$model->profile->username}\")' class='{$class}' title='" . Yii::t('app', 'Active status') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Credits'),
                'attribute' => 'credits',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = ($model->total_deposit && $model->total_withdrawal) ? $model->total_deposit - $model->total_withdrawal : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Credits') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Balance'),
                'attribute' => 'balance',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->balance ? $model->balance : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Balance') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Status'),
                'attribute' => 'status_without_discount',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->status_without_discount ? $model->status_without_discount : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    $title = Yii::t('app', 'Status');
                    $url = (Yii::$app->user->identity->isChild($model->getId())) ?
                        Url::to([
                            'debt-history/index',
                            'id' => $model->getId(),
                        ]) : Url::to([
                            'bet-history/index',
                            'player_id' => $model->getId(),
                            'date_from' => $this->date_from ? $this->date_from : $model->date()['date_from'],
                            'date_to' => $this->date_to ? $this->date_to : $model->date()['date_to'],
                            'number_of_weeks' => $this->number_of_weeks ? $this->number_of_weeks : 0,
                            'is_this_week_active' => $this->is_this_week_active == 0 ? $this->is_this_week_active : 1,
                        ]);
                    return "<a onclick='document.location=\"{$url}\"' title='{$title}' class='{$class} underline'>{$value}</a>";
                },
            ],
            [
                'label' => Yii::t('app', 'Rollover'),
                'attribute' => 'rollover',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $rollover = $model->rollover ? $model->rollover : 0;
                    $rollover = Formatter::formatNumberDisplay($rollover);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Rollover') . "'>{$rollover}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Total bets'),
                'attribute' => 'total_bets_count',
                'headerOptions' => ['class' => 'max-80'],
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatAmount(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->total_bets_count ? $model->total_bets_count : 0;
                    $value = Formatter::formatAmount($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Open bets') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Open bets'),
                'attribute' => 'open_bets_count',
                'headerOptions' => ['class' => 'max-80'],
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatAmount(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->open_bets_count ? $model->open_bets_count : 0;
                    $value = Formatter::formatAmount($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Open bets') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Open bets amount'),
                'attribute' => 'open_bets_sum',
                'format' => 'raw',
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'value' => function (PlayerSearch $model) {
                    $value = $model->open_bets_sum ? $model->open_bets_sum : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Open bets amount') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Discount - %'),
                'attribute' => 'discount',
                'headerOptions' => ['class' => 'max-80'],
                'format' => 'raw',
                'value' => function (User $model) {
                    $value = $model->getDiscountLimit() ? $model->getDiscountLimit() . '%' : '0%';
                    $class = $model->discountable_bets_count >= $model->profile->bet_limit ? 'bet_limit_positive' : 'status_zero';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Discount - %') . "'>{$value}</span>";
                }
            ],
            [
                'label' => Yii::t('app', 'Discount'),
                'attribute' => 'discount',
                'headerOptions' => ['class' => 'max-80'],
                'format' => 'raw',
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = (
                        $model->status_without_discount < 0 &&
                        $model->discountable_bets_count >= $model->profile->bet_limit &&
                        $model->discount
                    ) ?
                        $model->discount : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Discount') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Profit'),
                'attribute' => 'profit',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->profit ? $model->profit : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Profit') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Agent Profit'),
                'attribute' => 'agent_profit',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->agent_profit ? $model->agent_profit : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Agent Profit') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Bank Profit'),
                'attribute' => 'agent_bank_profit',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr highlighted_bg_row',
                ],
                'headerOptions' => [
                    'class' => 'highlighted_bg_row',
                ],
                'hidden' => Yii::$app->user->identity->isAgent() ? false : true,
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->agent_bank_profit ? $model->agent_bank_profit : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Bank Profit') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Master Profit'),
                'attribute' => 'master_profit',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr',
                ],
                'hidden' => Yii::$app->user->identity->isAgent() ? true : false,
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->master_profit ? $model->master_profit : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Master Profit') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Bank Profit'),
                'attribute' => 'master_bank_profit',
                'format' => 'raw',
                'contentOptions' => [
                    'class' => 'direction_ltr highlighted_bg_row',
                ],
                'headerOptions' => [
                    'class' => 'highlighted_bg_row',
                ],
                'hidden' => Yii::$app->user->identity->isAgent() ? true : false,
                'pageSummary' => function ($summary, $data, $widget) {
                    return Formatter::formatNumberDisplay(PlayerSearch::getTotal($data));
                },
                'value' => function (PlayerSearch $model) {
                    $value = $model->master_bank_profit ? $model->master_bank_profit : 0;
                    $value = Formatter::formatNumberDisplay($value);
                    $class = !($value < 0) ? ($value > 0) ? 'status_positive' : 'status_zero' : 'status_negative';
                    return "<span onclick='copyToClipboard(this)' class='{$class}' title='" . Yii::t('app', 'Bank Profit') . "'>{$value}</span>";
                },
            ],
            [
                'class' => 'yii\grid\ActionColumn',
                'header' => false,
                'contentOptions' => ['class' => 'text-center history-button'],
                'template' => '{bets}',
                'buttons' => [
                    'bets' => function ($url, PlayerSearch $model) {
                        $value = Yii::t('app', 'History');
                        $title = Yii::t('app', 'Bets');
                        $url = Url::to([
                            'bet-history/index',
                            'player_id' => $model->getId(),
                            'date_from' => $this->date_from ? $this->date_from : $model->date()['date_from'],
                            'date_to' => $this->date_to ? $this->date_to : $model->date()['date_to'],
                            'number_of_weeks' => $this->number_of_weeks ? $this->number_of_weeks : 0,
                            'is_this_week_active' => $this->is_this_week_active == 0 ? $this->is_this_week_active : 1,
                       ]);
                        return "<a onclick='document.location=\"{$url}\"' title='{$title}' class='btn btn-warning'>{$value}</a>";
                    },
                ]
            ],
            [
                'label' => Yii::t('app', 'Logged in'),
                'attribute' => 'is_logged_in',
                'format' => 'raw',
                'value' => function (User $model) {
                    $value = $model->isLoggedIn() ? Yii::t('app', PlayerSearch::getYesNo()['yes']) : Yii::t('app', PlayerSearch::getYesNo()['no']);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Logged in') . "'>{$value}</span>";
                }
            ],
            [
                'label' => Yii::t('app', 'Login time'),
                'attribute' => 'login_dt',
                'format' => 'raw',
                'headerOptions' => ['class' => 'max-130'],
                'value' => function (User $model) {
                    $value = Formatter::convertDate($model->created_dt, null, Yii::$app->params['date_format']);
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Login time') . "'>{$value}</span>";
                },
            ],
            [
                'label' => Yii::t('app', 'Login IP'),
                'attribute' => 'last_login_ip',
                'format' => 'raw',
                'value' => function (User $model) {
                    return "<span onclick='copyToClipboard(this)' title='" . Yii::t('app', 'Login IP') . "'>{$model->profile->last_login_ip}</span>";
                }
            ],
        ];
    }

    /**
     * Get statistics for box under player's table
     *
     * @return stdClass
     */
    public function getStats()
    {
        $total_to_spend = empty(Yii::$app->user->identity->profile) ? 0 : Yii::$app->user->identity->profile->getTotalToSpend();
        $available_balance = empty(Yii::$app->user->identity->profile) ? 0 : Yii::$app->user->identity->profile->getAvailableBalance();
        $stats = new stdClass();

        try {
            /** @var ActiveQuery $query */
            $subQuery = $this->playersSubQuery();

            $subQuery = $this->filters($subQuery);

            $query = User::find()
                ->alias('pl')
                ->select([
                    'status_without_discount' => new Expression("
                        CAST(
                            -SUM(
                                CASE
                                    WHEN [[psj]].[[status_without_discount]]
                                    THEN [[psj]].[[status_without_discount]]
                                    
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'status_with_discount' => new Expression(
                        "CAST(
                            -SUM(
                                CASE
                                    WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                        AND [[psj]].[[status_without_discount]] < 0
                                        AND [[psj]].[[status_with_discount]]
                                    THEN [[psj]].[[status_with_discount]]
                                    
                                    WHEN [[psj]].[[status_without_discount]]
                                    THEN [[psj]].[[status_without_discount]]
                                    
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )"
                    ),
                    'discount' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[discountable_bets_count]] >= [[psj]].[[bet_limit]]
                                        AND [[psj]].[[status_without_discount]] < 0
                                    THEN [[psj]].[[discount]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'agent_profit' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[agent_profit]]
                                    THEN [[psj]].[[agent_profit]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'master_profit' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[master_profit]]
                                    THEN [[psj]].[[master_profit]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'agent_bank_profit' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[agent_bank_profit]]
                                    THEN [[psj]].[[agent_bank_profit]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'master_bank_profit' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[master_bank_profit]]
                                    THEN [[psj]].[[master_bank_profit]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'total_debt' => new Expression("
                        CAST(
                            SUM(
                                CASE
                                    WHEN [[psj]].[[debt]]
                                    THEN [[psj]].[[debt]]
                
                                    ELSE 0
                                END
                            ) as DECIMAL(20," . Yii::$app->params['number_format']['decimals'] . ")
                        )
                    "),
                    'logged_in' => new Expression("
                        SUM(
                            CASE
                                WHEN ADDTIME([[psj]].[[last_active_dt]],'0:01:00.00') >= NOW()
                                THEN 1
                
                                ELSE 0
                            END
                        )
                    "),
                ])
                ->leftJoin(['psj' => $subQuery], '[[psj]].[[id]] = [[pl]].[[id]]');

            /** @var User $total */
            $total = $query->one();
        } catch (InvalidConfigException $e) {
            $stats->error = Yii::t('app', $e->getMessage());
            return $stats;
        }

        $stats->total_money_lost  = Formatter::formatNumberDisplay($total->status_without_discount);
        $stats->total_discount    = Formatter::formatNumberDisplay($total->discount);
        $stats->net_total         = Formatter::formatNumberDisplay($total->status_with_discount);
        $stats->agent_profit      = Formatter::formatNumberDisplay($total->agent_profit);
        switch (Yii::$app->user->identity->role) {
            case Constants::USER_ROLE_SUPER_AGENT:
                $stats->bank_profit  = Formatter::formatNumberDisplay($total->master_bank_profit);
                break;

            case Constants::USER_ROLE_MASTER_AGENT:
                $stats->bank_profit  = Formatter::formatNumberDisplay($total->master_profit);
                break;

            case Constants::USER_ROLE_AGENT:
                $stats->bank_profit  = Formatter::formatNumberDisplay($total->agent_bank_profit);
                break;
        }
        $stats->last_week_debt    = Formatter::formatNumberDisplay($total->total_debt / 100);
        $stats->money_to_spend    = Formatter::formatNumberDisplay($total_to_spend);
        $stats->available_balance = Formatter::formatNumberDisplay($available_balance);
        $stats->system            = Constants::SYSTEM_WEEKLY;
        $stats->discount          = Yii::$app->user->identity->getDiscountLimit();
        $stats->currency          = (Yii::$app->user->identity->getCurrency()) ?
                                        Yii::$app->user->identity->getCurrency() :
                                        CurrentUserHelper::getCurrency();
        $stats->player_logged_in  = Formatter::formatAmount($total->logged_in);

        return $stats;
    }

}
