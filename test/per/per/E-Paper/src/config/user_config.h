#ifndef __user_config_h__
#define __user_config_h__
#include <stdint.h>
#include <stdio.h>


//启用串口配网，命令[set ssid pass token per_sleep_time]  -  如 [set MI-WF 1234567 ABDCDEFG 0]
#define user_shell

//wifi信息
#define  user_ssid "aaaaa"        //wifi ssid
#define  user_pass "bbbbb"  //wifi password

//农历token
//https://www.alapi.cn/api/32/api_document  [在这里获取token]
#define nongli_token  "ccccc" 
 
//休眠时间 单位/分，0为每晚大约00点零5分刷一次
#define per_sleep_time "120"

//联网失败重试时间 单位/分，默认10分钟
#define per_sleep_time_retry 10

// //墨水屏引脚
#define user_busy_pin 26
#define user_rst_pin 27
#define user_dc_pin  30
#define user_cs_pin   28
#define user_sck_pin  29
#define user_mosi_pin 31

//ds1302和sht40引脚
#define dev_scl_pin 24
#define dev_sda_pin 25
#define dev_rst_pin 19
//sht40mos开关
#define user_sht40_switch 33


//拨轮开关
#define user_button0_pin 16
#define user_button1_pin 2
#define user_button2_pin 17

//通过开关按键唤醒
#define BT0_WAKE
// #define BT2_WAKE

//电池电压adc引脚
#define user_bat_adc_pin 0
//电池电压mos开关
#define user_bat_switch 32 



//充电插入检测引脚
#define user_charge_detect_pin 19
#endif