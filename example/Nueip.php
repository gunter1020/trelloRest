<?php
// API Key
define('API_KEY', '');

// API Token
define('API_TOKEN', '');

// RD Board
define('BOARD_RD_BACKEND', '');
define('BOARD_RD_FRONTEND', '');
define('BOARD_RD_CRM', '');

// QA Board
define('BOARD_QA', '');

require_once 'vendor/autoload.php';

use trelloRest\Client;

class Nueip
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * Search start date
     *
     * @var string
     */
    protected $start;

    /**
     * Search end date
     *
     * @var string
     */
    protected $end;

    /**
     * Cards map
     */
    private $_cardsMap = [];

    /**
     * Members name map
     */
    private $_membersName = [];

    /**
     * Construct
     */
    public function __construct($config)
    {
        // Get trello API
        $this->client = new Client(API_KEY, API_TOKEN);

        // Search date range
        $this->start = $config['start'];
        $this->end = $config['end'];

        // RD board card
        $this->_cardsMap['RD'] = array_merge(
            $this->cardMapBuilder(BOARD_RD_BACKEND),
            $this->cardMapBuilder(BOARD_RD_FRONTEND),
            $this->cardMapBuilder(BOARD_RD_CRM),
        );

        // QA board card
        $this->_cardsMap['QA'] = $this->cardMapBuilder(BOARD_QA);
    }

    /**
     * RD board Status
     *
     * @return void
     */
    public function devWeekCount()
    {
        $output = [];

        // Get all card
        $cardMap = array_merge($this->_cardsMap['RD'], $this->_cardsMap['QA']);

        foreach ($cardMap as $card) {
            $customFields = $card['customFields'];
            $group = $customFields['組別'] ?? '未指派';
            $size = $customFields['專案規模'] ?? 'X-未規劃';
            $devStart = $customFields['開發起始日'] ?? '0000-00-00';
            $devEnd = $customFields['開發完成日'] ?? '2099-12-31';

            preg_match('/([\w\.]+)#/', $card['name'], $matches);
            $cardDeveloper = $matches[1] ?? '未指派人員';
            $status = $this->_getStatus($devStart, $devEnd);
            $output[$group][$cardDeveloper][$status][$size][] = [
                'name' => "[{$card['name']}]({$card['shortUrl']})",
                'date' => "(**{$devStart}** ~ **{$devEnd}**)",
            ];
        }

        $filter = [
            '未處理' => [],
            '開發中' => [],
            '已完成' => [],
        ];

        foreach ($output as $group => $res) {
            ksort($res);
            $res = array_map(function ($row) use ($filter) {
                $intersect = array_intersect_key($row, $filter);
                foreach ($intersect as &$val) {ksort($val);}
                return count($intersect) ? array_merge($filter, $intersect) : null;
            }, $res);
            $this->_saveOutupt("{$group}.json", array_filter($res));
            $this->_saveMD("{$group}.md", array_filter($res));
        }
    }

    /**
     * RD board Status
     *
     * @return void
     */
    public function devMonthCount()
    {
        $output = [];

        $groupConv = [
            '維運' => 'HRM',
            '桃園' => 'HRM',
            '薪資' => 'HRM',
        ];

        // Get all card
        $cardMap = array_merge($this->_cardsMap['RD'], $this->_cardsMap['QA']);

        foreach ($cardMap as $card) {
            $customFields = $card['customFields'];
            $size = $customFields['專案規模'] ?? 'X-未規劃';
            $devStart = $customFields['開發起始日'] ?? null;
            $devEnd = $customFields['開發完成日'] ?? null;

            $group = $customFields['組別'] ?? '未指派';
            $group = $groupConv[$group] ?? $group;

            $status = $this->_getStatus($devStart, $devEnd);
            $output[$group][$status][$size][] = "[{$card['name']}]({$card['shortUrl']})";
        }

        $filter = [
            '未處理' => [],
            '開發中' => [],
            '已完成' => [],
        ];

        foreach ($output as $group => $groupCard) {
            $output[$group] = array_map(function ($row) {
                ksort($row);
                foreach ($row as &$val) {sort($val);}
                return $row;
            }, array_merge($filter, array_intersect_key($groupCard, $filter)));
        }

        $this->_saveOutupt('all.json', $output);
    }

    /**
     * QA board Status
     *
     * @return void
     */
    public function qualityWeekCount()
    {
        $output = [];

        $members = $this->_membersName;

        $defaultDate = '0000-00-00';

        // Get QA card
        $cardMap = $this->_cardsMap['QA'];

        foreach ($cardMap as $card) {
            $customFields = $card['customFields'];
            $testStart = $customFields['測試起始日'] ?? null;
            $testEnd = $customFields['測試完成日'] ?? null;
            $expect = $customFields['測試預定完成日'] ?? $defaultDate;

            $status = $this->_getStatus($testStart, $testEnd);
            $dateStamp = $testEnd ?? $testStart ?? $defaultDate;
            $cardName = "({$dateStamp}) (預定：{$expect}) {$card['name']}";
            $cardMembers = array_intersect_key($members, array_flip($card['idMembers']));

            foreach ($cardMembers as $memberName) {
                $output[$memberName][$status][] = [
                    'name' => $cardName,
                    'link' => $card['shortUrl'],
                ];
            }

            $output['all'][$status][] = [
                'name' => $cardName,
                'link' => $card['shortUrl'],
                'members' => implode(', ', $cardMembers),
            ];
        }

        // Get RD card
        $cardMap = $this->_cardsMap['RD'];

        foreach ($cardMap as $card) {
            $customFields = $card['customFields'];
            $testStart = $customFields['測試起始日'] ?? null;
            $testEnd = $customFields['測試完成日'] ?? null;
            $expect = $customFields['測試預定完成日'] ?? $defaultDate;

            $status = $this->_getStatus($testStart, $testEnd);

            if ($status !== '開發中' && $status !== '未處理') {
                $dateStamp = $testEnd ?? $testStart ?? $defaultDate;
                $cardName = "({$dateStamp}) (預定：{$expect}) {$card['name']}";
                $cardMembers = array_intersect_key($members, array_flip($card['idMembers']));

                foreach ($cardMembers as $memberName) {
                    $output[$memberName][$status][] = [
                        'name' => $cardName,
                        'link' => $card['shortUrl'],
                    ];
                }

                $output['all'][$status][] = [
                    'name' => $cardName,
                    'link' => $card['shortUrl'],
                    'members' => implode(', ', $cardMembers),
                ];
            }
        }

        $filter = [
            '未處理' => [],
            '開發中' => [],
            '已完成' => [],
            '預計完成' => [],
            '過去完成' => [],
        ];

        foreach ($output as $group => $res) {
            $res = array_merge($filter, array_intersect_key($res, $filter));
            $this->_saveOutupt("{$group}.json", $res);
        }
    }

    /**
     * field map builder
     *
     * @return array
     */
    protected function fieldMapBuilder($idBoard)
    {
        $fieldMap = [];

        $customFields = $this->client
            ->setBoard($idBoard)
            ->getBoardCustomFields();

        foreach ($customFields as $customField) {
            $name = $customField['name'];
            $type = $customField['type'];

            $options = [];
            if ($type === 'list') {
                foreach ($customField['options'] as $option) {
                    $options[$option['id']] = $option['value']['text'];
                }
            }

            $fieldMap[$customField['id']] = [
                'type' => $type,
                'name' => $name,
                'option' => $options,
            ];
        }

        return $fieldMap;
    }

    /**
     * card map builder
     *
     * @return array
     */
    protected function cardMapBuilder($idBoard)
    {
        $cardMap = [];

        $fieldMap = $this->fieldMapBuilder($idBoard);

        $cards = $this->client
            ->setBoard($idBoard)
            ->getBoardCards();

        foreach ($cards as $card) {
            // 過濾 UIUX 種類卡片統計
            if (preg_match('/\[uiux\]/i', $card['name'])) {
                continue;
            }

            $cardMap[$card['id']] = [
                'id' => $card['id'],
                'name' => $card['name'],
                'shortUrl' => $card['shortUrl'],
                'idMembers' => $card['idMembers'],
                'customFields' => [],
            ];
        }

        $cardsCustomFields = $this->client
            ->setCards(array_column($cardMap, 'id'))
            ->getCardsCustomFields();

        foreach ($cardsCustomFields as $cardsCustomField) {
            $idCard = $cardsCustomField['idModel'];
            $idCustomField = $cardsCustomField['idCustomField'];
            $field = $fieldMap[$idCustomField];

            switch ($field['type']) {
                case 'list':
                    $value = $field['option'][$cardsCustomField['idValue']] ?? null;
                    break;
                case 'date':
                    $value = date('Y-m-d', strtotime($cardsCustomField['value']['date']));
                    break;
            }

            $cardMap[$idCard]['customFields'][$field['name']] = $value;
        }

        return $cardMap;
    }

    /**
     * get card status by date
     *
     * @param  string   $cardStart
     * @param  string   $cardEnd
     * @return string
     */
    private function _getStatus($cardStart, $cardEnd)
    {
        if ($cardEnd !== '2099-12-31' && $cardEnd < $this->start) {
            return '過去完成';
        }

        if ($cardEnd !== '2099-12-31' && $cardEnd <= $this->end) {
            return '已完成';
        }

        if ($cardStart !== '0000-00-00') {
            return '開發中';
        }

        return '未處理';
    }

    /**
     * save output file
     *
     * @param  string $file
     * @param  array  $data
     * @return void
     */
    private function _saveOutupt($file, $data)
    {
        $function = debug_backtrace()[1]['function'];

        $savePath = implode(DIRECTORY_SEPARATOR, [
            'output', $function, 'json', $file,
        ]);

        !file_exists(dirname($savePath)) && mkdir(dirname($savePath), 0755, true);

        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($savePath, $contents);
    }

    /**
     * save format by markdown
     *
     * @param  string $file
     * @param  array  $data
     * @return void
     */
    private function _saveMD($file, $data)
    {
        $function = debug_backtrace()[1]['function'];

        $savePath = implode(DIRECTORY_SEPARATOR, [
            'output', $function, 'markdown', $file,
        ]);

        !file_exists(dirname($savePath)) && mkdir(dirname($savePath), 0755, true);

        $contents = [
            "# {$this->start} ~ {$this->end} 工作進度跟預計完成時間",
        ];

        $countSize = [];
        $countType = [];
        foreach ($data as $developer => $statusGroup) {
            foreach ($statusGroup as $status => $sizeGroup) {
                foreach ($sizeGroup as $size => $cardGroup) {
                    $countSize[$developer][$size][$status] = ($countSize[$developer][$size][$status] ?? 0) + count($cardGroup);
                    $countSize['總計'][$size][$status] = ($countSize['總計'][$size][$status] ?? 0) + count($cardGroup);
                    foreach ($cardGroup as $cardMeta) {
                        preg_match('/dev|patch|hotfix/i', $cardMeta['name'], $matches);
                        $cardType = ucfirst(strtolower($matches[0] ?? 'other'));
                        $countType[$developer][$cardType][$status] = ($countType[$developer][$cardType][$status] ?? 0) + 1;
                        $countType['總計'][$cardType][$status] = ($countType['總計'][$cardType][$status] ?? 0) + 1;
                    }
                }
            }
        }

        ksort($countSize);
        ksort($countType);

        $statusMap = ['未處理', '開發中', '已完成'];
        $sizeMap = ['A-1日內', 'B-5日內', 'C-10日內', 'D-20日內', 'E-超過20日', 'X-未規劃'];
        $typeMap = ['Hotfix', 'Patch', 'Dev', 'Other'];

        foreach ($data as $developer => $statusGroup) {
            $contents[] = '';
            $contents[] = '<div style="page-break-after: always;"></div>';
            $contents[] = '';
            $contents[] = "## {$developer} 專案統計";

            $contents[] = '';
            $contents[] = '| 專案規模   | ' . implode(' | ', $statusMap) . ' |';
            $contents[] = '| :--------- | -----: | -----: | -----: |';

            foreach ($sizeMap as $size) {
                $count = $countSize[$developer][$size] ?? [];
                $contentsRow = [str_pad($size, 10, ' ')];
                foreach ($statusMap as $status) {
                    $contentsRow[] = str_pad($count[$status] ?? '', 6, ' ', STR_PAD_LEFT);
                }
                $contents[] = '| ' . implode(' | ', $contentsRow) . ' |';
            }

            $contents[] = '';
            $contents[] = '| 專案種類   | ' . implode(' | ', $statusMap) . ' |';
            $contents[] = '| :--------- | -----: | -----: | -----: |';

            foreach ($typeMap as $type) {
                $count = $countType[$developer][$type] ?? [];
                $contentsRow = [str_pad($type, 10, ' ')];
                foreach ($statusMap as $status) {
                    $contentsRow[] = str_pad($count[$status] ?? '', 6, ' ', STR_PAD_LEFT);
                }
                $contents[] = '| ' . implode(' | ', $contentsRow) . ' |';
            }

            $contents[] = '';
            $contents[] = "## {$developer} 專案明細";

            foreach ($statusGroup as $status => $sizeGroup) {
                if (count($sizeGroup) === 0) {
                    continue;
                }

                $contents[] = '';
                $contents[] = "- {$status}";
                foreach ($sizeGroup as $size => $cardGroup) {
                    if (count($cardGroup) === 0) {
                        continue;
                    }

                    $contents[] = "  - {$size}";
                    foreach ($cardGroup as $cardMate) {
                        $contents[] = "    - {$cardMate['name']}";

                        switch ($status) {
                            case '開發中':
                                $contents[] = "      - 開發起訖: {$cardMate['date']}";
                                $contents[] = "      - 項目進度: ";
                                break;
                            case '已完成':
                                $contents[] = "      - 開發起訖: {$cardMate['date']}";
                                $contents[] = "      - 項目進度: 進入測試序列 | 結案";
                                break;
                            case '未處理':
                            default:
                                $contents[] = "      - 項目進度: 預排工作事項";
                                break;
                        }
                    }
                }
            }
        }

        $contents[] = '';

        file_put_contents($savePath, implode("\n", $contents));
    }
}

switch ($argv[1] ?? '') {
    default:
    case 'week':
        (new Nueip([
            'start' => date('Y-m-d', strtotime('last week monday')),
            'end' => date('Y-m-d', strtotime('last week sunday')),
        ]))->devWeekCount();
        break;
    case 'month':
        (new Nueip([
            'start' => date('Y-m-d', strtotime('first day of last month')),
            'end' => date('Y-m-d', strtotime('last day of last month')),
        ]))->devMonthCount();
        break;
}
