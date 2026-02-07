/*****************************************************************************
* | File      	:   DEV_Config.c
* | Author      :   Waveshare team
* | Function    :   Hardware underlying interface
* | Info        :
*----------------
* |	This version:   V1.0
* | Date        :   2020-02-19
* | Info        :
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documnetation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to  whom the Software is
# furished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS OR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.
#
******************************************************************************/
#include "DEV_Config.h"

static struct bflb_device_s *gpio;
static struct bflb_device_s *spi0;
void digitalWrite(UWORD GPIO_Pin, UWORD value)
{
    // bflb_gpio_init(gpio,GPIO_Pin,GPIO_OUTPUT);
    if(value == 0) {
        bflb_gpio_reset(gpio,GPIO_Pin);
	} else {
        bflb_gpio_set(gpio,GPIO_Pin);
	}
}

UBYTE digitalRead(UWORD GPIO_Pin)
{
    bflb_gpio_init(gpio,GPIO_Pin,GPIO_INPUT|GPIO_PULLUP);
    return bflb_gpio_read(gpio,GPIO_Pin);
}


void GPIO_Mode(UWORD GPIO_Pin, UWORD Mode)
{

    if(Mode == 0) {
        bflb_gpio_init(gpio,GPIO_Pin,GPIO_INPUT|GPIO_PULLUP);
	} else {
		bflb_gpio_init(gpio,GPIO_Pin,GPIO_OUTPUT);
	}
}


/******************************************************************************
function:	Module Initialize, the BCM2835 library and initialize the pins, SPI protocol
parameter:
Info:
******************************************************************************/
UBYTE DEV_Module_Init(void)
{
	// spi
	gpio = bflb_device_get_by_name("gpio");
    //busy
    bflb_gpio_init(gpio,EPD_BUSY_PIN,GPIO_INPUT|GPIO_PULLUP);
    // RST
    bflb_gpio_init(gpio, EPD_RST_PIN, GPIO_OUTPUT | GPIO_PULLUP);
    // DC
    bflb_gpio_init(gpio, EPD_DC_PIN, GPIO_OUTPUT | GPIO_PULLUP);
    // CS
    bflb_gpio_init(gpio, EPD_CS_PIN, GPIO_FUNC_SPI0 | GPIO_ALTERNATE | GPIO_PULLUP | GPIO_SMT_EN | GPIO_DRV_1);
    //SCL
    bflb_gpio_init(gpio, EPD_SCK_PIN, GPIO_FUNC_SPI0 | GPIO_ALTERNATE | GPIO_PULLUP | GPIO_SMT_EN | GPIO_DRV_1);
    //SDA
    bflb_gpio_init(gpio, EPD_MOSI_PIN, GPIO_FUNC_SPI0 | GPIO_ALTERNATE | GPIO_PULLUP | GPIO_SMT_EN | GPIO_DRV_1);


    struct bflb_spi_config_s spi_cfg =
    {
        .freq = 10 * 1000 * 1000,
        .role = SPI_ROLE_MASTER,
        .mode = SPI_MODE0,
        .data_width = SPI_DATA_WIDTH_8BIT,
        .bit_order = SPI_BIT_MSB,
        .byte_order = SPI_BYTE_LSB,
        .tx_fifo_threshold = 0,
        .rx_fifo_threshold = 0,
    };

    spi0 = bflb_device_get_by_name("spi0");
    bflb_spi_init(spi0, &spi_cfg);
    bflb_spi_feature_control(spi0, SPI_CMD_SET_CS_INTERVAL, 0);

	return 0;
}

/******************************************************************************
function:
			SPI read and write
******************************************************************************/
void DEV_SPI_WriteByte(UBYTE data)
{
    bflb_spi_poll_send(spi0, data);
}

void DEV_SPI_Write_nByte(UBYTE *pData, UDOUBLE len)
{
    for (int i = 0; i < len; i++){
        bflb_spi_poll_send(spi0, pData[i]);
    }
}
