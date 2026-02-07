#ifndef _PER_DRAW_
#define _PER_DRAW_
#include "DEV_Config.h"
#include "EPD.h"
#include "GUI_Paint.h"
#include <stdlib.h>
#include "per_img.h"

#include "bl616_pm.h" 
#include "SHT40.h"
#include "DS1302.h"
#include "adc_vbat.h"

#include "wifi.h"
#include "sys_code.h"

static UBYTE *BlackImage, *RYImage; // Red or Yellow
static char *Week_table[] = {"星期一", "星期二", "星期三", "星期四", "星期五", "星期六", "星期日"};
//时间结构体
PAINT_TIME sPaint_time;
static struct bflb_device_s *gpio;

//=====per_draw======================================================================
void user_task(void); //  启动任务
void Per_InIt(void); // 初始化墨水屏
void Per_DisPlay(void);//显示内容到屏幕
void Per_ToSleep(void);//墨水屏进入休眠状态
void Per_DrawDate(PAINT_TIME *pTime, uint8_t mode); // 绘制日历
void per_show(uint8_t flag); // 显示内容到屏幕

//==================================
// 主线程
static void dsplay_task(void* arg);
// 绘制方框
static void set_rect(uint16_t _row, uint16_t _col, uint16_t w);
// 计算某年某月某日是星期几
static int get_weekday(int year, int mon, int day);
// 计算某年某月的天数
static int getDaysInMonth(int year, int month);
//=====================================================================================


void Per_InIt(void){
    DEV_Module_Init();
    printf("e-Paper Init...\r\n");
    EPD_4IN2B_V2_Init_1();
    // EPD_4IN2B_V2_Clear_1();
    DEV_Delay_ms(50);
    UWORD Imagesize = ((EPD_4IN2B_V2_WIDTH % 8 == 0) ? (EPD_4IN2B_V2_WIDTH / 8 ) : (EPD_4IN2B_V2_WIDTH / 8 + 1)) * EPD_4IN2B_V2_HEIGHT;
    if ((BlackImage = (UBYTE *)malloc(Imagesize)) == NULL) {
        printf("Failed to apply for black memory...\r\n");
        while(1);
    }
    if ((RYImage = (UBYTE *)malloc(Imagesize)) == NULL) {
        printf("Failed to apply for red memory...\r\n");
        while(1);
    }
    printf("NewImage:BlackImage and RYImage\r\n");
    Paint_NewImage(BlackImage, EPD_4IN2B_V2_WIDTH, EPD_4IN2B_V2_HEIGHT, 0, WHITE);
    Paint_NewImage(RYImage, EPD_4IN2B_V2_WIDTH, EPD_4IN2B_V2_HEIGHT, 0, WHITE);
    Paint_SelectImage(RYImage);
    Paint_Clear(WHITE);
    Paint_SelectImage(BlackImage);
    Paint_Clear(WHITE);
}   


void Per_DrawDate(PAINT_TIME *pTime, uint8_t mode){ 
    Paint_SelectImage(RYImage);
    //电压低于3.6v时显示电量低
    uint32_t vbat_val = adc_vbat_read();
    //显示电压/时间
    #if 0
        Paint_DrawNum(85, 5, vbat_val, &Font8, BLACK, WHITE);
        Paint_DrawString_EN(107, 5, "mv", &Font8,WHITE,BLACK);
        Paint_DrawTime(20,100, &sPaint_time, &Font16,WHITE,BLACK);
    #endif

    if(bflb_gpio_read(gpio,user_charge_detect_pin)==0){
        Paint_DrawImage(perImage_xl,10,2,20,10);
        Paint_DrawString_CN(30, 0, "充电中", &Font12CN, BLACK, WHITE);
        
    }else{

        if (vbat_val>3800){
            Paint_SelectImage(BlackImage);
            Paint_DrawImage(perImage_xl,10,2,20,10);
        }
        else if (vbat_val>3650){
            Paint_SelectImage(BlackImage);
            Paint_DrawImage(perImage_zcl,10,2,20,10);
        }
        else{
            Paint_DrawImage(perImage_bgx,10,2,20,10);
            Paint_DrawString_CN(30, 0, "电量低", &Font12CN, BLACK, WHITE);
            Paint_DrawNum(85, 5, vbat_val, &Font8, BLACK, WHITE);
            Paint_DrawString_EN(105, 5, "mv", &Font8,WHITE,BLACK);
        }
        if(vbat_val>3650 && holiday_info.is_success == 1){
            //节假日显示
            switch (holiday_info.type){
                Paint_SelectImage(BlackImage);
                case 0:
                    Paint_DrawString_CN(35, 1, "工作日", &Font8CN, BLACK, WHITE);
                    break;
                case 1:
                    Paint_DrawString_CN(35, 1, "周末", &Font8CN, BLACK, WHITE);
                    break;
                case 2:
                    Paint_SelectImage(RYImage);
                    char str_name[30];
                    sprintf(str_name, "%s假期", holiday_info.name);
                    Paint_DrawString_CN(35, 1,str_name, &Font8CN, BLACK, WHITE);
                break;
            default:
                Paint_DrawString_CN(35, 1, "调休", &Font8CN, BLACK, WHITE);
                break;
            }
        }
    }

    Paint_SelectImage(RYImage);

    //联网失败
    if(mode == 255){
        Paint_DrawString_CN(95, 20, "网络连接失败！", &Font24_kaiti, BLACK, WHITE);
        Paint_DrawLine(0,60,400,60,BLACK,DOT_PIXEL_2X2,LINE_STYLE_SOLID);
        Paint_DrawImage(perImage_bq,92,80,215,215);
        return ;
    }

    //数据获取失败
    if(mode == 0){
        Paint_DrawString_CN(95, 20, "数据获取失败！", &Font24_kaiti, BLACK, WHITE);
        Paint_DrawLine(0,60,400,60,BLACK,DOT_PIXEL_2X2,LINE_STYLE_SOLID);
        Paint_DrawImage(perImage_bq,92,80,215,215);
        return ;
    }
    //绘制信息
    uint16_t week_x = 58,week_y = 65;

    //== 绘制星期
    Paint_DrawString_CN(week_x * 5, week_y, "周六", &Font18CN, BLACK, WHITE);
    Paint_DrawString_CN(week_x * 6, week_y, "周日", &Font18CN, BLACK, WHITE);

    Paint_SelectImage(BlackImage);
    Paint_DrawLine(0,60,400,60,BLACK,DOT_PIXEL_2X2,LINE_STYLE_SOLID);
    Paint_DrawString_CN(5, week_y, "周一", &Font18CN, BLACK, WHITE);
    Paint_DrawString_CN(week_x, week_y, "周二", &Font18CN, BLACK, WHITE);
    Paint_DrawString_CN(week_x * 2, week_y, "周三", &Font18CN, BLACK, WHITE);
    Paint_DrawString_CN(week_x * 3 , week_y, "周四", &Font18CN, BLACK, WHITE);
    Paint_DrawString_CN(week_x * 4 , week_y, "周五", &Font18CN, BLACK, WHITE);


    uint8_t start_week =  get_weekday(pTime->Year,pTime->Month,1);
    uint8_t days = getDaysInMonth(pTime->Year,pTime->Month);
    // printf("本月1号是周%d,本月有%d天\r\n",start_week,days);
    uint8_t row = 7-start_week,day_y=40;
    int8_t day = pTime->Day;



    //剩余天数超出一页显示
    int8_t residue_day = days-(row+1)-28;
    if(residue_day > 0 && day>25){
        Paint_SelectImage(BlackImage);
        for(u_int8_t n = 0;n < residue_day ;n++){
            Paint_DrawNum(week_x*n +15, week_y + day_y, row+30+n, &Font20, BLACK, WHITE);
        }
        // ==== 绘制方框
        day = day - (row+1)-28;
        set_rect(0,day - 1,35);
    }else{
        // ==== 绘制方框
        if(day <= row+1){
            if(day > 0){
                set_rect(0,6-((row+1)-day),35);
            }
        }else{
            //列
            uint16_t cloumn = (day - (row+1))%7;
            cloumn = cloumn == 0 ? 6 : cloumn - 1;
            //行
            uint16_t rowss = (day - (row+1));//(day - (row+1))/7+1;
            if (rowss <= 7) {
                rowss = 1;
            } else if (rowss <= 14) {
                rowss = 2;
            } else if (rowss <= 21) {
                rowss = 3;
            } else {
                rowss = 4;
            }
            // rowss = day<=10 ? rowss+1 : rowss;
            set_rect(rowss,cloumn,35);
        }
        //== 绘制日期
        //第一行
        for(int i = 0;i <= row;i++){
            start_week+i<=5 ? Paint_SelectImage(BlackImage) : Paint_SelectImage(RYImage);
            Paint_DrawNum(week_x*(start_week+i-1)+15, week_y + day_y, i+1, &Font20, BLACK, WHITE);
        }

        for(uint8_t i = 0;i < 4;i++){
            for(uint8_t j = 0;j < 7;j++){
                j <= 4 ? Paint_SelectImage(BlackImage) : Paint_SelectImage(RYImage);
                uint8_t num = row+2+j+i*7 > days ? 0 : row+2+j+i*7;
                //超出本月日期num置0默认不显示
                // if (num == 1) {
                //     num = 0;
                // }
                if(j==0){
                    Paint_DrawNum(15, (week_y+ day_y*2)+day_y*i, num, &Font20, BLACK, WHITE);
                }else{
                    Paint_DrawNum(week_x*j+15, (week_y+ day_y*2)+day_y*i, num, &Font20, BLACK, WHITE);
                }
                
            }
        }


    }

    //今天日期
    Paint_SelectImage(RYImage);
    char str_Month[4],str_Day[4];
    sprintf(str_Month, "%02d", pTime->Month);
    sprintf(str_Day, "%02d", pTime->Day);

    Paint_DrawString_CN(5, 15,str_Month, &Font24CN, BLACK, WHITE);
    Paint_DrawString_CN(35, 15, "月", &Font24_kaiti, BLACK, WHITE);
    Paint_DrawString_CN(65, 15, str_Day, &Font24CN, BLACK, WHITE);  
    Paint_DrawString_CN(95, 15, "日", &Font24_kaiti, BLACK, WHITE);

    Paint_SelectImage(BlackImage);
    //星期
    uint8_t _week = get_weekday(pTime->Year,pTime->Month,pTime->Day);
    Paint_DrawString_CN(320, 30, Week_table[_week-1], &Font18CN, BLACK, WHITE);


    //农历
    char _nl[100]; // 拼接后的字符串
    snprintf(_nl, sizeof(_nl), "%s年%s%s",nong_li.ganzhi_year, nong_li.month, nong_li.day);

    Paint_DrawString_CN(135, 5, _nl, &Font16CN, BLACK, WHITE);
    //170,200,210,220,255
    //温湿度
    Paint_SelectImage(RYImage);
    float temperature, humidity;
    SHT40_init(dev_scl_pin, dev_sda_pin);  // SCL ，SDA 
    SHT40_GetData(&temperature, &humidity);
    // printf("温度: %.2f °C, 湿度: %.2f %%\n", temperature, humidity);
    Paint_DrawNum(315, 7, (int32_t)temperature, &Font20, BLACK, WHITE);
    Paint_DrawNum(360, 7, (int32_t)humidity, &Font20, BLACK, WHITE);
    Paint_SelectImage(BlackImage);
    Paint_DrawString_CN(340, 3, "°", &Font16CN, BLACK, WHITE);
    Paint_DrawString_CN(350, 3, "|", &Font16CN, BLACK, WHITE);
    Paint_DrawString_CN(388, 3, "%", &Font16CN, BLACK, WHITE);

    //定位
    Paint_DrawImage(perImage_dw,130,37,12,12);
    Paint_DrawString_CN(140, 35,weather_info.location, &Font12CN, BLACK, WHITE);

    //气温
    Paint_DrawNum(178, 35, weather_info.low, &Font16, BLACK, WHITE);
    Paint_DrawChar(203, 30, '_', &Font12, BLACK, WHITE);
    Paint_DrawNum(213, 35, weather_info.high, &Font16, BLACK, WHITE);
    Paint_DrawString_CN(233, 30, "°", &Font16CN, BLACK, WHITE);

    //天气
    char* _weather_str = weather_str(weather_info.code_day);
    Paint_DrawString_CN(250, 35, _weather_str, &Font12CN, BLACK, WHITE);

}

// 绘制日期方框块
//参数一：行
///参数二：列
///参数三：宽度
static void set_rect(uint16_t _row, uint16_t _col, uint16_t w){
    if((_col<0&&_col>4) || (_row<0&&_row>6)){
        return;
    };
    uint16_t x_start,y_start;
    x_start = 10  + (_col * 58);
    y_start =  95 + (_row * 40);
    Paint_SelectImage(RYImage);
    Paint_DrawImage(perImage_ax,x_start,y_start-5,45,45);
    // Paint_DrawRectangle(x_start, y_start, x_start + w, y_start + w, BLACK, DOT_PIXEL_2X2, DRAW_FILL_EMPTY);
}

void Per_DisPlay(void){
    EPD_4IN2B_V2_Display_1(BlackImage, RYImage);
}

//休眠
void Per_ToSleep(void){
    printf("Goto Sleep...\r\n");
    EPD_4IN2B_V2_Sleep_1();
    free(BlackImage);
    free(RYImage);
    BlackImage = NULL;
    RYImage = NULL;
}


//=============================
static TaskHandle_t _dsplay_task;
static uint8_t update_flag = 0;

void per_show(uint8_t flag) {
    Per_InIt();
    switch (flag) {
        case 0:
            // 数据获取失败
            Per_DrawDate(NULL, 0);
            break;

        case 255:
            // 网络连接失败
            Per_DrawDate(NULL, 255);
            break;
        default:
            // 正常显示信息
            //2025-05-09 22:59:52
            DS1302_Init(dev_scl_pin, dev_sda_pin, dev_rst_pin);
            #if 1
                int year, month, day, hour, minute, second;
                if (sscanf(weather_info.date_time, "%d-%d-%d %d:%d:%d", 
                        &year, &month, &day, &hour, &minute, &second) == 6) {
                    // 将解析结果填入 TimeBuff
                    TimeBuff[0] = year % 100; // 年份的后两位
                    TimeBuff[1] = month;      // 月
                    TimeBuff[2] = day;        // 日
                    TimeBuff[3] = get_weekday(year,month,day);       // 星期
                    TimeBuff[4] = hour;       // 时
                    TimeBuff[5] = minute;     // 分
                    TimeBuff[6] = second;     // 秒
                    DS1302_Write_Time(); // 写入时间
                } else {
                    printf("解析失败，请检查日期时间格式是否正确！\n");

                }
            #endif
            DS1302_Read_Time();
            sPaint_time.Year = 2000 + TimeBuff[0];
            sPaint_time.Month = TimeBuff[1];
            sPaint_time.Day = TimeBuff[2];
            sPaint_time.Week = TimeBuff[3];
            sPaint_time.Hour = TimeBuff[4];
            sPaint_time.Min = TimeBuff[5];
            sPaint_time.Sec = TimeBuff[6];
            // printf("20%d年 %d月 %d日 星期%d %d时 %d分 %d秒\n",TimeBuff[0],TimeBuff[1],TimeBuff[2],TimeBuff[3],TimeBuff[4],TimeBuff[5],TimeBuff[6]);
            Per_DrawDate(&sPaint_time, 1);
            break;
    }

    Per_DisPlay();
    Per_ToSleep();

    //进入hbn模式
    uint32_t _s_time = 0;
    switch (flag){
        case 0:
            _s_time = per_sleep_time_retry;
            printf("数据获取失败.\r\n");
            break;
        case 255:
            printf("wifi连接失败.\r\n");
            _s_time = per_sleep_time_retry;
            break;
    default:
        
        //计算到晚上12点零5分的时差，分
        
        if(user_info.sleep_time == 0){
            _s_time = 1450 - (sPaint_time.Hour * 60 + sPaint_time.Min);
        }else{
            _s_time = user_info.sleep_time;
        }
        break;
    }
    //进入pds休眠
    sys_to_sleep(_s_time);  //120ma-125ma -> 1.6ma/1.5ma  ds1302 = 0.05ma
    // pm_hbn_mode_enter(PM_HBN_LEVEL_0, (32768 * 60)*_s_time);//300ua
}

static void dsplay_task(void* arg)
{   
    for(int i = 0; i < 60; i++){

        if(_wifi_status_code == 7 && update_flag == 0){
            update_flag = 1;
            printf("wifi连接成功!\r\n");
            per_show(http_update_data());
        }
        vTaskDelay(1000);
    }
    printf("网络连接失败,10秒后进入休眠模式!\r\n");
    vTaskDelay(10000);
    //10分钟后唤醒继续配网
    per_show(255);
}


void user_task(void)
{   
    gpio = bflb_device_get_by_name("gpio");
    bflb_gpio_init(gpio,user_charge_detect_pin,GPIO_INPUT|GPIO_PULLUP);
    xTaskCreate(dsplay_task, "dsplay_task", 1024*10, 5, NULL, &_dsplay_task);
}



// 函数：计算某年某月某日是星期几
// 参数：year - 年份，mon - 月份（1到12），day - 日期（1到31）
// 返回值：1=星期一，...，7=星期日
static int get_weekday(int year, int mon, int day) {
    int m = mon;
    int d = day;
    // 根据月份对年份和月份进行调整
    if (m <= 2) {
        year -= 1;
        m += 12;
    }
    int c = year / 100; // 取得年份前两位
    int y = year % 100; // 取得年份后两位

    // 根据泰勒公式计算星期
    int w = (c / 4) - 2 * c + y + (y / 4) + (13 * (m + 1) / 5) + d - 1;
    return  w % 7 == 0 ? 7 : w % 7; // 返回星期
}


// 函数：计算某年某月的天数
// 参数：year - 年份，mon - 月份（1到12）
// 返回值：本月天数
static int getDaysInMonth(int year, int month) {
    // 每个月的天数（非闰年）
    int daysInMonth[] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};

    // 判断是否是闰年（使用三目运算符）
    int isLeap = (year % 4 == 0 && year % 100 != 0) || (year % 400 == 0) ? 1 : 0;

    // 如果是2月且是闰年，返回29天
    if (month == 2 && isLeap) {
        return 29;
    }

    // 返回对应月份的天数
    return daysInMonth[month - 1];
}


#endif