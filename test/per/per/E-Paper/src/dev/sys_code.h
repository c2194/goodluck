#ifndef __SYS_CODE_H__
#define __SYS_CODE_H__
#include "bl616_glb.h"
#include "bl616_pds.h"
#include "bl616_hbn.h"
#include "bl616_aon.h"
#include "bl616_pm.h"
#include "user_config.h"

#define BL_LP_IO_RES_PULL_UP   1  /*!< 低功耗I/O配置为上拉模式 */
#define BL_LP_IO_RES_PULL_DOWN 2  /*!< 低功耗I/O配置为下拉模式 */
#define BL_LP_IO_RES_NONE      3  /*!< 低功耗I/O配置为开漏模式 */



static void bl_lp_set_aon_io(int pin, int trigMode, int res_mode)
{
    /* set pin's GLB_GPIO_FUNC_Type as GPIO_FUN_GPIO */
    GLB_GPIO_Cfg_Type gpio_cfg;
    uint8_t pu, pd;

    gpio_cfg.drive = 0;
    gpio_cfg.smtCtrl = 0;
    gpio_cfg.outputMode = 0;
    gpio_cfg.gpioMode = GPIO_MODE_INPUT;
    gpio_cfg.pullType = GPIO_PULL_NONE;
    gpio_cfg.gpioPin = (GLB_GPIO_Type)pin;
    gpio_cfg.gpioFun = GPIO_FUN_GPIO;
    GLB_GPIO_Init(&gpio_cfg);

    if (res_mode == BL_LP_IO_RES_PULL_UP) {
        pu = 1;
        pd = 0;
    } else if (res_mode == BL_LP_IO_RES_PULL_DOWN) {
        pu = 0;
        pd = 1;
    } else {
        pu = 0;
        pd = 0;
    }

    /* set pin's aonPadCfg */
    HBN_AON_PAD_CFG_Type aonPadCfg;
    uint32_t mask = 0;

    aonPadCfg.ctrlEn = 1;
    aonPadCfg.ie = 1;
    aonPadCfg.oe = 0;
    aonPadCfg.pullUp = pu;
    aonPadCfg.pullDown = pd;
    HBN_Aon_Pad_Cfg(ENABLE, (pin - 16), &aonPadCfg);

    mask = BL_RD_REG(HBN_BASE, HBN_IRQ_MODE);
    mask = BL_GET_REG_BITS_VAL(mask, HBN_PIN_WAKEUP_MASK);
    mask = mask & ~(1 << (pin - 16));

    /* set trigMode */
    HBN_Aon_Pad_WakeUpCfg(DISABLE, (uint8_t)trigMode, mask, 0, 7);

    /* UnMask Hbn_Irq Wakeup PDS*/
    pm_pds_wakeup_src_en(PDS_WAKEUP_BY_HBN_IRQ_OUT_EN_POS);
}

static void bl_lp_set_pds_io(int pin, int trigMode, int res_mode)
{
    /* set pin's GLB_GPIO_FUNC_Type as GPIO_FUN_GPIO */
    GLB_GPIO_Cfg_Type gpio_cfg;
    uint8_t pu, pd;
    uint8_t gpio_grp;
    uint32_t tmpVal;

    if (pin > 19) {
        gpio_grp = (pin - 4) / 8;
    } else {
        gpio_grp = pin / 8;
    }

    gpio_cfg.drive = 0;
    gpio_cfg.smtCtrl = 0;
    gpio_cfg.outputMode = 0;
    gpio_cfg.gpioMode = GPIO_MODE_INPUT;
    gpio_cfg.pullType = GPIO_PULL_NONE;
    gpio_cfg.gpioPin = (GLB_GPIO_Type)pin;
    gpio_cfg.gpioFun = GPIO_FUN_GPIO;
    GLB_GPIO_Init(&gpio_cfg);

    if (res_mode == BL_LP_IO_RES_PULL_UP) {
        pu = 1;
        pd = 0;
    } else if (res_mode == BL_LP_IO_RES_PULL_DOWN) {
        pu = 0;
        pd = 1;
    } else {
        pu = 0;
        pd = 0;
    }

    PDS_Set_GPIO_Pad_Pn_Pu_Pd_Ie(gpio_grp / 2, pu, pd, 1);

    PDS_Set_GPIO_Pad_IntClr(gpio_grp);
    PDS_Set_GPIO_Pad_IntMode(gpio_grp, trigMode);

    tmpVal = BL_RD_REG(PDS_BASE, PDS_GPIO_PD_SET);
    if (pin > 19) {
        tmpVal &= ~(1 << (pin - 4));
    } else {
        tmpVal &= ~(1 << pin);
    }
    BL_WR_REG(PDS_BASE, PDS_GPIO_PD_SET, tmpVal);

    pm_pds_wakeup_src_en(PDS_WAKEUP_BY_PDS_GPIO_IRQ_EN_POS);
}


void sys_to_sleep(int sleep_time){

    uint32_t tmpVal = 0;
    /* rf808_usb20_psw_rref output off */
    tmpVal = BL_RD_REG(PDS_BASE, PDS_USB_PHY_CTRL);
    tmpVal = BL_CLR_REG_BIT(tmpVal, PDS_REG_PU_USB20_PSW);
    BL_WR_REG(PDS_BASE, PDS_USB_PHY_CTRL, tmpVal);

    pm_pds_mask_all_wakeup_src();
    AON_Output_Float_LDO15_RF();
    HBN_Pin_WakeUp_Mask(0xF);
    sleep_time = sleep_time > 0 ? sleep_time : 120;
    printf("距离下次唤醒时间还有%d分\r\n",sleep_time);
    printf("进入休眠...\r\n");
    bflb_mtimer_delay_ms(100);
    // bl_lp_set_pds_io(0, PDS_GPIO_INT_SYNC_RISING_FALLING_EDGE, BL_LP_IO_RES_PULL_DOWN);
    #ifdef BT0_WAKE
    bl_lp_set_aon_io(user_button0_pin, HBN_GPIO_INT_TRIGGER_SYNC_HIGH_LEVEL, BL_LP_IO_RES_PULL_DOWN);
    #endif
    #ifdef BT2_WAKE
    bl_lp_set_aon_io(user_button2_pin, HBN_GPIO_INT_TRIGGER_SYNC_HIGH_LEVEL, BL_LP_IO_RES_PULL_DOWN);
    #endif
    bl_lp_set_aon_io(user_charge_detect_pin, HBN_GPIO_INT_TRIGGER_SYNC_RISING_FALLING_EDGE, BL_LP_IO_RES_PULL_UP);
    /* sleep time must set zero to avoid using rtc */
    pm_pds_mode_enter(PM_PDS_LEVEL_15, 32768*60*sleep_time);
}

//用户数据结构体
typedef struct Per_User_Info{
    char ssid[32];    //wifissid
    char passwd[32];  //wifipasswd
    char nl_token[32];   //农历token
    uint32_t sleep_time;  //休眠时间
} Per_User_Info;
Per_User_Info user_info;

//=========easy_flash===================================================

#ifdef user_shell
#include "bflb_mtd.h"
#include "easyflash.h"


void es_flash_init(void);
uint8_t es_flash_read(Per_User_Info *_info);
uint8_t es_flash_write(Per_User_Info *_info);
void es_flash_init(void){

    bflb_mtd_init();
    easyflash_init();
}

uint8_t es_flash_write(Per_User_Info *_info) {

    if (_info == NULL) {
        return 0;  // 参数无效
    }
    if (ef_set_and_save_env(user_ssid, (const char *)_info->ssid)) return 0;
    if (ef_set_and_save_env(user_pass, (const char *)_info->passwd)) return 0;
    if (ef_set_and_save_env(nongli_token, _info->nl_token)) return 0;
    char sleep_time_str[12]; // 足够存储 uint32_t 的字符串表示
    sprintf(sleep_time_str, "%u", _info->sleep_time);
    if (ef_set_env(per_sleep_time, sleep_time_str) != 0) return 0;
    if(ef_save_env()) return 0;
    return 1;
}
uint8_t es_flash_read(Per_User_Info *_info) {

    if (_info == NULL) {
        return 0; 
    }

    memset(_info, 0, sizeof(Per_User_Info));
    int ret;
    if (ef_get_env(user_ssid) == NULL) return 0;
    ret = ef_get_env_blob(user_ssid, _info->ssid, sizeof(_info->ssid), NULL);
    _info->ssid[ret] = 0;

    if (ef_get_env(user_pass) == NULL) return 0;
    ret = ef_get_env_blob(user_pass, _info->passwd, sizeof(_info->passwd), NULL);
    _info->passwd[ret] = 0;
    
    if (ef_get_env(nongli_token) == NULL) return 0;
    ret = ef_get_env_blob(nongli_token, _info->nl_token, sizeof(_info->nl_token), NULL);
    _info->nl_token[ret] = 0;

    char sleep_time_str[12];
    if (ef_get_env(per_sleep_time) == NULL) return 0;
    if (ef_get_env_blob(per_sleep_time, sleep_time_str, sizeof(sleep_time_str), NULL) < 0) return 0;

    _info->sleep_time = atoi(sleep_time_str);

    return 1;  
}
//=====shell==========================================================================
#include "bflb_uart.h"
#include "shell.h"
#include "semphr.h"

void shell_init(void);
uint8_t cmd_set_config(int argc, char **argv);
static struct shell *shell;
extern void shell_init_with_task(struct bflb_device_s *shell);

void sys_shell_init(void) {
    static struct bflb_device_s *uart0;
    uart0 = bflb_device_get_by_name("uart0");
    shell_init_with_task(uart0);
    
}
uint8_t cmd_set_config(int argc, char **argv)
{
    printf("argc:%d\n",argc);
    if(argc < 5){
        printf("参数错误\n");
        return -1;
    }
    strcpy(user_info.ssid, argv[1]);
    strcpy(user_info.passwd, argv[2]);
    strcpy(user_info.nl_token, argv[3]);
    user_info.sleep_time = atoi(argv[4]);
    es_flash_write(&user_info);
    vTaskDelay(500);
    GLB_SW_System_Reset(); //系统复位
    return 0;
}
SHELL_CMD_EXPORT_ALIAS(cmd_set_config, set, ssid pass token sleep_time);
#endif
//user_init=====================================================================

void user_init(void) {
    strcpy(user_info.ssid, user_ssid);
    strcpy(user_info.passwd, user_pass);
    strcpy(user_info.nl_token, nongli_token);
    user_info.sleep_time = atoi(per_sleep_time);
    #ifdef user_shell
        sys_shell_init();
        es_flash_init();
        es_flash_read(&user_info);
    #endif
}


#endif