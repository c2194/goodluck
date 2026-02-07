#ifndef __HTTP_GET_H__
#define __HTTP_GET_H__

#include <unistd.h>
#include <stdlib.h>
#include <stdio.h>
#include <sys/socket.h>
#include <lwip/api.h>
#include <lwip/arch.h>
#include <lwip/opt.h>
#include <lwip/inet.h>
#include <lwip/errno.h>
#include <netdb.h>
#include "utils_getopt.h"

#include "cJSON.h"

typedef struct WeatherInfo{
    char date_time[20];          // 日期，如 "2025-05-09 13:54:19"
    char weather[20];           // 白天天气状况，如 "中雨"
    char text_night[20];         // 夜间天气状况，如 "小雨"
    char location[20];           //  城市名称
    uint8_t code_day;                    // 天气状态码
    uint8_t high;                    // 最高气温
    uint8_t low;                     // 最低气温
    float rainfall;              // 降雨量
    float precip;                // 降水概率
    char wind_direction[10];     // 风向，如 "北"
    uint16_t wind_direction_degree;   // 风向角度，如 0
    float wind_speed;            // 风速
    uint8_t wind_scale;              // 风力等级
    uint8_t humidity;                // 湿度
    uint8_t is_success;          //是否成功
} WeatherInfo;

WeatherInfo weather_info;

typedef struct NongLi{
    char year[20];           //农历年份
    char month[10];          //农历月份
    char day[10];            //农历日期
    char ganzhi_year[10];    //农历干支
    char animal[10];         //农历生肖
    char yi[50];             //农历宜
    char ji[50];             //农历忌
    char caishen_desc[10];   //财神方位
    char xishen_desc[10];    //喜神方位
    char fushen_desc[10];    //福神方位
    uint8_t is_success;      //是否成功
} NongLi;

NongLi nong_li;

// HTTP响应的状态码和数据体
typedef struct HttpResponse{
    int status_code;
    char* body;
} HttpResponse;

HttpResponse http_response;


#define  IPORT "80"
static uint8_t recv_buf[1024*4]={0,0,0};
static ssize_t total_cnt = 0;
static int sock_client = -1;

//=====http_get===================================================================
uint8_t http_update_data();  //联网获取天气，时间
char* weather_str(uint8_t code); //通过天气代码获取天气字符串
NongLi set_nongli(const char* str_lunar); //设置农历信息
WeatherInfo set_weather_info(const char* str_daily);//设置天气信息
HttpResponse parse_http_response(const char* response); //解析HTTP响应
static uint8_t http_get(char* url, char* path,bool ver); //HTTP请求
//===============================================================================

static uint8_t http_get(char* url, char* path,bool ver)
{
    printf("Http client task start ...\r\n");

    char *host_name;
    char *addr;
    char *port;
    port = IPORT;
    struct sockaddr_in remote_addr;

    /* get address (argv[1] if present) */
    host_name = url;

    #ifdef LWIP_DNS
        ip4_addr_t dns_ip;
        // 查询主机名对应的IP地址,host_name:域名，dns_ip:存放DNS解析后IP地址的变量
        netconn_gethostbyname(host_name, &dns_ip);

        // 将IP地址转换为字符串,dns_ip:存放DNS解析后IP地址的变量
        addr = ip_ntoa(&dns_ip);
    #endif

    while (1) {

        // 建立socket连接
        if ((sock_client = socket(AF_INET, SOCK_STREAM, 0)) < 0) {
            printf("Http Client create socket error\r\n");
            return 0;
        }
        remote_addr.sin_family = AF_INET; // 设置地址族为 IPv4
        remote_addr.sin_port = htons(atoi(port)); // 设置端口号，将端口号由字符串转换为整数，并使用网络字节顺序表示
        remote_addr.sin_addr.s_addr = inet_addr(addr); // 设置IP地址，将IP地址由字符串转换为二进制表示
        memset(&(remote_addr.sin_zero), 0, sizeof(remote_addr.sin_zero)); // 清零 sin_zero 域

        printf("Host:%s, Server ip Address : %s:%s\r\n", host_name, addr, port);

        // 建立一个TCP连接,连接到远程服务器或设备,它会在成功建立连接时返回 0，如果发生错误，则返回一个错误代码
        if (connect(sock_client, (struct sockaddr *)&remote_addr, sizeof(struct sockaddr)) != 0) {
            printf("Http请求连接失败!\r\n");
            //关闭一个网络套接字（socket）
            closesocket(sock_client);
            return 0;
        }

        printf("Http client connect server success!\r\n");
        
        memset(recv_buf, 0, sizeof(recv_buf));
        total_cnt = 0;

        // uint8_t get_buf[] = "GET /api/lunar?token=LwExDtUWhF3rH5ib HTTP/1.1\r\nHost: v2.alapi.cn\r\n\r\n";

        uint8_t get_buf[512] = {0};

        snprintf((char*)get_buf, sizeof(get_buf), "GET %s HTTP/1.%d\r\nHost: %s\r\n\r\n", path,ver,url);
        // printf("get_buf:\r\n%s\r\n", get_buf);

        //发送数据
        write(sock_client, get_buf, sizeof(get_buf));

        while (1) {
            total_cnt = recv(sock_client, (uint8_t *)recv_buf, sizeof(recv_buf), MSG_DONTWAIT);
            vTaskDelay(200);
            // printf("len: %d\r\n", total_cnt);
            
            if (total_cnt == -1 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
                // 当前没有数据可接收，继续等待
                continue;
            } 
            // printf("%s\r\n", (uint8_t *)recv_buf);
            break;
        }
        // printf("结束...\r\n");
        closesocket(sock_client);
        return 1;
    }
}




// 解析HTTP响应
HttpResponse parse_http_response(const char* response) {
    HttpResponse result;
    result.status_code = -1; // 初始化状态码为-1，表示未找到
    result.body = NULL;

    // 检查输入是否为空
    if (response == NULL || strlen(response) == 0) {
        return result;
    }

    // 查找状态码
    const char* status_line = strstr(response, "HTTP/1.1 ");
    if (status_line) {
        // 提取状态码
        char status_code_str[4];
        sscanf(status_line + 9, "%3s", status_code_str); // 跳过"HTTP/1.1 "，读取3个字符
        result.status_code = atoi(status_code_str);
    }

    // 查找数据体
    const char* body_start = strstr(response, "\r\n\r\n");
    if (body_start) {
        // 数据体从"\r\n\r\n"之后开始
        body_start += 4; // 跳过"\r\n\r\n"
        result.body = strdup(body_start); // 复制数据体
    }

    return result;
}

//天气

WeatherInfo set_weather_info(const char* str_daily){
    WeatherInfo _weather = {0};
    cJSON* root = cJSON_Parse(str_daily);
    if (root==NULL) {
        LOG_I("is not json");
        return _weather;
    }

    cJSON* results = cJSON_GetObjectItem(root, "results"); 
    
    // printf("results: %s\n", cJSON_Print(results));

    cJSON* result = cJSON_GetArrayItem(results, 0);
    // printf("result: %s\n", cJSON_Print(result));

    // 获取location 位置、时间
    cJSON* location = cJSON_GetObjectItem(result, "location");
    cJSON* name = cJSON_GetObjectItem(location, "name");
    snprintf(_weather.location, sizeof(_weather.location), "%s", name->valuestring);

    // printf("name: %s\n", cJSON_Print(name));
    cJSON* nowtime = cJSON_GetObjectItem(location, "nowtime");
    snprintf(_weather.date_time, sizeof(_weather.date_time), "%s", nowtime->valuestring);
    // printf("nowtime: %s\n", cJSON_Print(nowtime));
    //获取daily  天气
    cJSON* dailys = cJSON_GetObjectItem(result, "daily");
    cJSON* daily = cJSON_GetArrayItem(dailys, 0);
    // printf("daily: %s\n", cJSON_Print(daily));
    //天气文字
    cJSON* text_day = cJSON_GetObjectItem(daily, "text_day");
    snprintf(_weather.weather, sizeof(_weather.weather), "%s", text_day->valuestring);
    // _weather.weather = text_day->valuestring;
    // printf("天气: %s\n", text_day->valuestring);
    //天气代码
    cJSON* code_day = cJSON_GetObjectItem(daily, "code_day");
    _weather.code_day  = atoi(code_day->valuestring);
    // printf("code_day: %s\n", cJSON_Print(code_day));
    //最高温度
    cJSON* high = cJSON_GetObjectItem(daily, "high");
    _weather.high = atoi(high->valuestring);
    // printf("最高温度: %s\n", high->valuestring);
    //最低温度
    cJSON* low = cJSON_GetObjectItem(daily, "low");
    _weather.low = atoi(low->valuestring);
    cJSON_Delete(root);
    _weather.is_success = 1;
    // printf("最低温度: %s\n", low->valuestring);
    return _weather;
}


//农历
NongLi set_nongli(const char* str_lunar){
    NongLi _nong_li = {0};
    cJSON* root = cJSON_Parse(str_lunar);
    if (root==NULL) {
        LOG_I("is not json");
        return _nong_li;
    }
    cJSON* code = cJSON_GetObjectItem(root, "code");
    if(code->valueint != 200){
        // LOG_I("农历获取失败\r\n");
        return _nong_li;
    }
    cJSON* datas = cJSON_GetObjectItem(root, "data");
    
    // printf("datas: %s\n", cJSON_Print(datas));
    cJSON* lunar_year = cJSON_GetObjectItem(datas, "lunar_year_chinese");
    cJSON* lunar_month = cJSON_GetObjectItem(datas, "lunar_month_chinese");
    cJSON* lunar_day = cJSON_GetObjectItem(datas, "lunar_day_chinese");
    snprintf(_nong_li.year, sizeof(_nong_li.year), "%s", lunar_year->valuestring); //年
    snprintf(_nong_li.month, sizeof(_nong_li.month), "%s", lunar_month->valuestring); //月
    snprintf(_nong_li.day, sizeof(_nong_li.day), "%s", lunar_day->valuestring);  //日
    cJSON* gan_zhi_year = cJSON_GetObjectItem(datas, "ganzhi_year");
    snprintf(_nong_li.ganzhi_year, sizeof(_nong_li.ganzhi_year), "%s", gan_zhi_year->valuestring);  //干支年
    cJSON* caishen_desc = cJSON_GetObjectItem(datas, "caishen_desc");
    snprintf(_nong_li.caishen_desc, sizeof(_nong_li.caishen_desc), "%s", caishen_desc->valuestring);  //财神方位
    _nong_li.is_success = 1;
    return _nong_li;
}

//========节假日==========================================
typedef struct Holiday_Info{
    uint8_t type;           // 节假日类型，enum(0, 1, 2, 3)分别表示 工作日、周末、节日、调休。
    char name[30];          // 节假日类型中文名，可能值为 周一 至 周日、假期的名字、某某调休
    uint8_t wage;          // 薪资倍数
    uint8_t is_success;    // 是否成功

} Holiday_Info;

Holiday_Info holiday_info;


Holiday_Info set_holiday(const char* str_holiday){
    Holiday_Info _holiday = {0};
    cJSON* root = cJSON_Parse(str_holiday);
    if (root==NULL) {
        LOG_E("is not json");
        return _holiday;
    }
    cJSON* code = cJSON_GetObjectItem(root, "code");
    if(code->valueint != 0){
        LOG_E("假期信息获取失败\r\n");
        return _holiday;
    }
    //datas: {
    // 	"type":	0,
    // 	"name":	"周二",
    // 	"week":	2
    //}
    cJSON* datas = cJSON_GetObjectItem(root, "type");
    // printf("datas: %s\n", cJSON_Print(datas));
    cJSON* type = cJSON_GetObjectItem(datas, "type");
    cJSON* name = cJSON_GetObjectItem(datas, "name");
    _holiday.type =  type->valueint;
    snprintf(_holiday.name, sizeof(_holiday.name), "%s", name->valuestring); //节假日名字
    _holiday.is_success = 1;
    return _holiday;
}

uint8_t http_update_data(){
    // 天气、时间
    http_get("haohaodada.com","/project/weather/",true);
    http_response = parse_http_response(recv_buf);
    if(http_response.status_code == -1){
        printf("Failed to parse HTTP response.\n");
        return 0;
    }
    // printf("Body: %s\n", http_response.body);
    weather_info = set_weather_info(http_response.body);
    if(weather_info.is_success == 0){
        printf("json解析失败!.%s\n",http_response.body);
        return 0;
    }
    printf("时间:%s\n",weather_info.date_time);
    printf("天气:%s\n",weather_info.weather);
    printf("天气代码:%d\n",weather_info.code_day);
    printf("城市:%s\n",weather_info.location);
    printf("最低气温:%d\n",weather_info.low);
    printf("最高气温:%d\n",weather_info.high);


    // 农历
    uint8_t get_path[50] = {0};
    snprintf((char*)get_path, sizeof(get_path), "/api/lunar?token=%s",user_info.nl_token);
    http_get("v3.alapi.cn",get_path,true); 
    http_response = parse_http_response(recv_buf);
    if(http_response.status_code == -1){
        printf("Failed to parse HTTP response.\n");
        return 0;
    }
    nong_li = set_nongli(http_response.body);
    if(nong_li.is_success == 0){
        printf("json解析失败!.%s\n",http_response.body);
        return 0;
    }
    printf("农历年:%s\n",nong_li.year);
    printf("农历月:%s\n",nong_li.month);
    printf("农历日:%s\n",nong_li.day);
    printf("农历干支:%s\n",nong_li.ganzhi_year);
    printf("财神方位:%s\n",nong_li.caishen_desc);
    
    http_get("timor.tech","/api/holiday/info",false);
    http_response = parse_http_response(recv_buf);
    if(http_response.status_code == -1){
        printf("Failed to parse HTTP response.\n");
        return 0;
    }
    // printf("Body: %s\n", http_response.body);
    holiday_info = set_holiday(http_response.body);
    if(holiday_info.is_success == 0){
        printf("json解析失败!.%s\n",http_response.body);
        return 0;
    }
    printf("节假日类型:%d\n",holiday_info.type);
    printf("详情:%s\n",holiday_info.name);
    return 1;
}

char* weather_str(uint8_t code){
    static const char* _weather_str;
    switch (code) {
        case 0:
            _weather_str = "白天晴";
            break;
        case 1:
            _weather_str = "夜晚晴";
            break;
        case 4:
            _weather_str = "多云";
            break;
        case 5:
            _weather_str = "晴间多云";
            break;
        case 6:
            _weather_str = "晴间多云";
            break;
        case 7:
            _weather_str = "大部多云";
            break;
        case 8:
            _weather_str = "大部多云";
            break;
        case 9:
            _weather_str = "阴天";
            break;
        case 10:
            _weather_str = "阵雨";
            break;
        case 11:
            _weather_str = "雷阵雨";
            break;
        case 12:
            _weather_str = "雷阵雨伴有冰雹";
            break;
        case 13:
            _weather_str = "小雨";
            break;
        case 14:
            _weather_str = "中雨";
            break;
        case 15:
            _weather_str = "大雨";
            break;
        case 16:
            _weather_str = "暴雨";
            break;
        case 17:
            _weather_str = "大暴雨";
            break;
        case 18:
            _weather_str = "特大暴雨";
            break;
        case 19:
            _weather_str = "冻雨";
            break;
        case 20:
            _weather_str = "雨夹雪";
            break;
        case 21:
            _weather_str = "阵雪";
            break;
        case 22:
            _weather_str = "小雪";
            break;
        case 23:
            _weather_str = "中雪";
            break;
        case 24:
            _weather_str = "大雪";
            break;
        case 25:
            _weather_str = "暴雪";
            break;
        case 26:
            _weather_str = "浮尘";
            break;
        case 27:
            _weather_str = "扬沙";
            break;
        case 28:
            _weather_str = "沙尘暴";
            break;
        case 29:
            _weather_str = "强沙尘暴";
            break;
        case 30:
            _weather_str = "雾霾";
            break;
        case 31:
            _weather_str = "雾霾";
            break;
        case 32:
            _weather_str = "微风";
            break;
        case 33:
            _weather_str = "大风";
            break;
        case 34:
            _weather_str = "飓风";
            break;
        case 35:
            _weather_str = "热带风暴";
            break;
        case 36:
            _weather_str = "龙卷风";
            break;
        case 37:
            _weather_str = "冷";
            break;
        case 38:
            _weather_str = "热";
            break;
        default:
            _weather_str = "未知";
            break;
    }
    return _weather_str;
}

#endif
