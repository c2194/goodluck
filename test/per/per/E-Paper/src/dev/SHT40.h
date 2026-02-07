#ifndef __SHT40_h
#define __SHT40_h

#include "bflb_mtimer.h"
#include "bflb_gpio.h"
#include "bflb_i2c.h"
#include "user_config.h"

static struct bflb_device_s *i2c0;
static struct bflb_device_s *gpio;
static struct bflb_i2c_msg_s msgs[2];
static uint8_t read_data[8];  // 用于存储读取的数据


// SHT40 设备地址
#define SHT40_I2C_ADDRESS 0x44

//=====SHT40==================================================================
void SHT40_init(uint8_t _scl, uint8_t _sda);//初始化sht40
uint8_t SHT40_GetData(float *temperature, float *humidity); //获取温湿度数据
//=============================================================================

// 初始化 SHT40
void SHT40_init(uint8_t _scl, uint8_t _sda) {
    gpio = bflb_device_get_by_name("gpio");
    bflb_gpio_init(gpio,user_sht40_switch,GPIO_OUTPUT);
    /* I2C0_SCL */
    bflb_gpio_init(gpio, _scl, GPIO_FUNC_I2C0 | GPIO_ALTERNATE | GPIO_PULLUP | GPIO_SMT_EN | GPIO_DRV_1);
    /* I2C0_SDA */
    bflb_gpio_init(gpio, _sda, GPIO_FUNC_I2C0 | GPIO_ALTERNATE | GPIO_PULLUP | GPIO_SMT_EN | GPIO_DRV_1);
    i2c0 = bflb_device_get_by_name("i2c0");
    bflb_i2c_init(i2c0, 400000);  // 初始化 I2C，设置波特率为 400kHz
}

// 读取温湿度数据
uint8_t SHT40_GetData(float *temperature, float *humidity) {
    bflb_gpio_set(gpio,user_sht40_switch);
    bflb_mtimer_delay_ms(200);
    uint8_t command = 0xFD;  // 测量命令（高精度模式）
    uint16_t temp_raw, hum_raw;

    // // 发送测量命令
    msgs[0].addr = SHT40_I2C_ADDRESS;
    msgs[0].flags = 0;
    msgs[0].buffer = &command;
    msgs[0].length = 1;
    int8_t ret = bflb_i2c_transfer(i2c0, msgs, 1);
    if(ret<0){
        *temperature = 0;
        *humidity = 0;
        return 0;
    }
    // 等待测量完成（根据测量模式，需要一定时间）
    bflb_mtimer_delay_ms(100);

    // 读取数据
    msgs[0].addr = SHT40_I2C_ADDRESS;
    msgs[0].flags = I2C_M_READ;
    msgs[0].buffer = read_data;
    msgs[0].length = 6;

    bflb_i2c_transfer(i2c0, msgs, 1);
    // 解析数据
    temp_raw = (read_data[0] << 8) | read_data[1];
    hum_raw = (read_data[3] << 8) | read_data[4];

    // 转换为温度和湿度值
    *temperature = -45.0 + 175.0 * temp_raw / 65535.0;
    *humidity = -6.0 + 125.0 * hum_raw / 65535.0;

    // 限制湿度值在 0% 到 100% 之间
    *humidity = (*humidity > 100.0) ? 100.0 : (*humidity < 0.0 ? 0.0 : *humidity);
    bflb_gpio_reset(gpio,user_sht40_switch);
    return 1;
}


#endif