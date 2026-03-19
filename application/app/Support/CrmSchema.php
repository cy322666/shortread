<?php

namespace App\Support;

class CrmSchema
{
    public const STATUS_CHECKOUT_VIEWED = 81927994; //первый
    public const STATUS_PAYMENT_COMPLETE = 142;
    public const STATUS_PAYMENT_FAILED = 83819658;
    public const STATUS_ORDER_ABANDONED = 81926778;
    public const STATUS_RECURRENT_PAYMENT = 142;

    public const STATUSES = [
        'checkout_viewed' => [
            'id' => self::STATUS_CHECKOUT_VIEWED,
            'name' => 'Начало оформления',
        ],
        'payment_complete' => [
            'id' => self::STATUS_PAYMENT_COMPLETE,
            'name' => 'Оплачен',
        ],
        'payment_failed' => [
            'id' => self::STATUS_PAYMENT_FAILED,
            'name' => 'Оплата не прошла',
        ],
        'order_abandoned' => [
            'id' => self::STATUS_ORDER_ABANDONED,
            'name' => 'Брошенный заказ',
        ],
        'recurrent_payment' => [
            'id' => self::STATUS_RECURRENT_PAYMENT,
            'name' => 'Продление подписки',
        ],
    ];

    public const FIELDS = [
        'lead' => [
            'order_id' => [
                'id' => 348373,
                'name' => 'ID заказа',
            ],
            'parent_order_id' => [
                'id' => 409159,
                'name' => 'Parent order ID',
            ],
            'product' => [
                'id' => 348265,
                'name' => 'Товар',
            ],
            'created_at' => [
                'id' => 348259,
                'name' => 'Дата создания заказа',
            ],
            'paid_at' => [
                'id' => 348261,
                'name' => 'Дата оплаты',
            ],
            'status' => [
                'id' => 348263,
                'name' => 'Статус заказа',
            ],
            'period_subscribe' => [
                'id' => 348283,
                'name' => 'Период подписки',
            ],
            'payment_method' => [
                'id' => 348269,
                'name' => 'Способ оплаты',
            ],
            'customer_type' => [
                'id' => 348271,
                'name' => 'Юрлицо или физлицо',
            ],
            'subtotal' => [
                'id' => 348273,
                'name' => 'Цена за единицу',
            ],
            'quantity' => [
                'id' => 348275,
                'name' => 'Количество',
            ],
            'total' => [
                'id' => 348277,
                'name' => 'Итоговая сумма заказа',
            ],
            'origin' => [
                'id' => 348279,
                'name' => 'Источник',
            ],
            'product_name' => [
                'id' => 348285,
                'name' => 'Наименование товара',
            ],
            'access_count' => [
                'id' => 409153,
                'name' => 'Количество доступов',
            ],
            'is_recurrent' => [
                'id' => 409155,
                'name' => 'Рекуррентный заказ',
            ],
            'error_reason' => [
                'id' => 0,
                'name' => 'Причина ошибки оплаты',
            ],
            'subscription_start_at' => [
                'id' => 0,
                'name' => 'Дата начала подписки',
            ],
            'subscription_end_at' => [
                'id' => 0,
                'name' => 'Дата окончания подписки',
            ],
            'recurrent_type' => [
                'id' => 409157,
                'name' => 'Тип продления',
            ],
        ],
        'contact' => [
            'user_id' => [
                'id' => 409161,
                'name' => 'User ID',
            ],
            'email' => [
                'id' => 348289,
                'name' => 'Email',
            ],
            'username' => [
                'id' => 348287,
                'name' => 'Username',
            ],
            'pseudonym' => [
                'id' => 411797,
                'name' => 'Псевдоним',
            ],
        ],
        'company' => [
            'inn' => [
                'id' => 348495,
                'name' => 'ИНН',
            ],
        ],
        'catalog' => [
            'id' => 3059,
            'name' => 'Товары',
            'fields' => [
                'article' => [
                    'id' => 348293,
                    'name' => 'Артикул',
                ],
                'price' => [
                    'id' => 348297,
                    'name' => 'Цена',
                ],
            ],
        ],
    ];
}
