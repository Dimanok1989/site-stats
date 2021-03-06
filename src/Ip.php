<?php

namespace Kolgaev\IpInfo;

use Exception;
use Kolgaev\IpInfo\Models\AutomaticBlock;
use Kolgaev\IpInfo\Models\Block;
use Kolgaev\IpInfo\Models\Statistic;
use Kolgaev\IpInfo\Models\Visit;

class Ip extends DataBase
{
    /**
     * Флаг блокировки клиента
     * 
     * @var null|bool
     */
    protected $block = null;

    /**
     * Флаг автоматической блокировки клиента
     * 
     * @var null|bool
     */
    protected $auto_block = null;

    /**
     * Флаг блокировки клиента по имени хоста
     * 
     * @var null|bool
     */
    protected $host_block = null;

    /**
     * Массив ошибок
     * 
     * @var array
     */
    protected $errors = [];

    /**
     * Массив ответа
     * 
     * @var array
     */
    protected $default_response = [
        'block' => null,
        'block_auto' => null,
        'block_host' => null,
        'block_period' => null,
        'block_ip' => null,
        'requests' => 0,
        'visits' => 0,
        'visits_drops' => 0,
        'visits_all' => 0,
        'ip' => null,
    ];

    /**
     * Проверка ip, учет статистики и вывод данных
     * 
     * @return array
     */
    public function check()
    {
        $this->ip = $this->ip();
        $this->host = gethostbyaddr($this->ip);

        $this->block = $this->checkBlock();

        $story = $this->writeStory();

        $response = [
            'block' => $this->block ?? null,
            'block_auto' => $this->auto_block ?? null,
            'block_host' => $this->host_block ?? null,
            'block_period' => $this->period_block ?? null,
            'block_ip' => $this->ip_block ?? null,
            'ip' => $this->ip,
        ];

        if (count($this->errors)) {
            $response['errors'] = $this->errors;
        }

        return array_merge($this->default_response, $story, $response);
    }

    /**
     * Проверка блокировки
     * 
     * @return bool|null
     */
    public function checkBlock()
    {
        if ($block = $this->checkBlockIp())
            return $block;

        $this->host_block = $this->checkBlockHost();

        return $this->host_block !== null ? $this->host_block : $block;
    }

    /**
     * Проверка наличия блокировки IP
     * 
     * @return bool|null
     */
    public function checkBlockIp()
    {
        if ($ip_block = $this->checkBlockIpAddr())
            return $ip_block;

        if ($auto_block = $this->checkAutoBlock())
            return $auto_block;

        if ($period_block = $this->checkBlockPeriod())
            return $period_block;

        return false;
    }

    /**
     * Првоерка блокировки по жесткому совпадению адреса
     * 
     * @return bool|null
     */
    public function checkBlockIpAddr()
    {
        try {
            return $this->ip_block = Block::whereHost($this->ip)->whereIsBlock(1)->count() > 0;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    /**
     * Проверка блокировки по диапазону
     * 
     * @return bool|null
     */
    public function checkBlockPeriod()
    {
        if (!$long = ip2long($this->ip))
            return null;

        try {
            $period_block = Block::whereIsPeriod(1)
                ->whereIsBlock(1)
                ->where([
                    ['period_start', '<=', $long],
                    ['period_stop', '>=', $long],
                ])
                ->count();

            return $this->period_block = $period_block > 0;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    /**
     * Проверка автоматической блокировки
     * 
     * @return bool|null
     */
    public function checkAutoBlock()
    {
        try {
            $row = AutomaticBlock::whereIp($this->ip)->where('date', date("Y-m-d"))->first();

            if (is_integer($row->drop_block ?? null))
                return $this->auto_block = $row->drop_block != 1;

            return $this->auto_block = $row ? true : false;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    /**
     * Проверка блокировки по хосту
     * 
     * @return bool|null
     */
    public function checkBlockHost()
    {
        if (!$this->host)
            return false;

        try {
            $hosts = Block::select('host')
                ->where([
                    ['is_hostname', 1],
                    ['is_block', 1],
                ])
                ->get();

            foreach ($hosts as $row) {
                if (strripos($this->host, $row->host) !== false or $this->ip == $row->host) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    /**
     * Запись истории посещения
     * 
     * @return array
     */
    public function writeStory()
    {
        try {
            Visit::create([
                'ip' => $this->ip,
                'is_blocked' => (bool) $this->block,
                'page' => $_SERVER['REQUEST_URI'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'referer' => $_SERVER['HTTP_REFERER'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'request_data' => [
                    'headers' => getallheaders(),
                    'post' => $_POST ?? null,
                    'get' => $_GET ?? null,
                ],
                'created_at' => date("Y-m-d H:i:s"),
            ]);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }

        try {
            $statistic = Statistic::firstOrNew([
                'date' => date("Y-m-d"),
                'ip' => $this->ip,
            ]);

            if ($this->block)
                $statistic->visits_drops++;
            else
                $statistic->visits++;

            $statistic->hostname = $this->host;
            $statistic->save();

            $statistic = $statistic->only('visits', 'requests', 'visits_drops');

            return array_merge($statistic, [
                'visits_all' => $statistic['visits'] + $statistic['visits_drops'],
            ]);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }

        return [];
    }

    /**
     * Проверка ip клиента
     * 
     * @param bool $array Вернуть массив адресов
     * @return string|null
     */
    public function ip($array = false)
    {
        $list = [];

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = explode(',', $_SERVER['HTTP_CLIENT_IP']);
            $list = array_merge($list, $ip);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $list = array_merge($list, $ip);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $list[] = $_SERVER['REMOTE_ADDR'];
        }

        $list = array_unique($list);

        return $array ? $list : ($list[0] ?? null);
    }
}
