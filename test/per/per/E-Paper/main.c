/* Includes ------------------------------------------------------------------*/
#include "per_draw.h"
#include "board.h"
#include "wifi.h"
#include <FreeRTOS.h>
#include "sys_code.h"
//125ma/1.5ma
int main(void)
{
    board_init();
    user_init();
    wifi_connect();
    user_task();

    vTaskStartScheduler();
    while(1){
        bflb_mtimer_delay_ms(1000);
    }
}
