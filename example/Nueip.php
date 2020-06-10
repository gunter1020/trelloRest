<?php
// API Key
define('API_KEY', '');

// API Token
define('API_TOKEN', '');

// RD Board
define('BOARD_RD', '');

// QA Board
define('BOARD_QA', '');

require_once 'vendor\autoload.php';

use trelloRest\Client;

class Nueip
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * Construct
     */
    public function __construct()
    {
        $this->client = new Client(API_KEY, API_TOKEN);
    }

    /**
     * RD board Status
     *
     * @return void
     */
    public function devWeekCount()
    {
        // Get last week
        $weekStart = date('Y-m-d', strtotime('last week monday'));
        $weekEnd = date('Y-m-d', strtotime('last week sunday'));

        // Get all card
        $cardMap = $this->cardMapBuilder(BOARD_RD) + $this->cardMapBuilder(BOARD_QA);

        $output = [];
        foreach ($cardMap as $card) {
            $customFields = $card['customFields'];
            $group = $customFields['組別'] ?? '未指派';
            $size = $customFields['專案規模'] ?? 'X-未規劃';
            $devStart = $customFields['開發起始日'] ?? null;
            $devEnd = $customFields['開發完成日'] ?? null;

            if (isset($devEnd)) {
                $status = '已完成';
                if (($devEnd < $weekStart) || ($devEnd > $weekEnd)) {
                    continue;
                }
            } elseif (isset($devStart)) {
                $status = '處理中';
            } else {
                $status = '未處理';
            }

            $dateStamp = $devEnd ?? $devStart ?? '0000-00-00';
            $cardName = "({$dateStamp}) {$card['name']}";

            $output[$group][$status][$size][] = [
                'name' => $cardName,
                'link' => $card['shortUrl'],
            ];
        }

        foreach ($output as $group => $res) {
            $contents = json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents("output\\{$group}.json", $contents);
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
            $cardMap[$card['id']] = [
                'id' => $card['id'],
                'name' => $card['name'],
                'shortUrl' => $card['shortUrl'],
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
}

(new Nueip)->devWeekCount();
