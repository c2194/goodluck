#ifndef __DS1302_H__
#define __DS1302_H__
#include <bflb_gpio.h>
#include <bflb_mtimer.h>


static struct bflb_device_s *gpio;
uint8_t TimeBuff[7]={25,5,5,1,23,06,00};				// 时间数组，年月日，星期，时:分:秒
uint8_t _scl,_sda,_rst;
/*********************************************************/
// 从DS1302读出一字节数据
/*********************************************************/
uint8_t DS1302_Read_Byte(uint8_t addr) 
{
	uint8_t i;
	uint8_t temp = 0;
	bflb_gpio_init(gpio,_sda, GPIO_OUTPUT);
	bflb_gpio_set(gpio, _rst); 						
	
	/* 写入目标地址：addr*/
	for(i=0;i<8;i++) 
	{     
		if(addr&0x01) 
			bflb_gpio_set(gpio, _sda); 
		else 
			bflb_gpio_reset(gpio, _sda); 
		bflb_gpio_set(gpio, _scl); 
		bflb_mtimer_delay_us(5);
		bflb_gpio_reset(gpio, _scl);
		bflb_mtimer_delay_us(5);
		
		addr=addr>> 1;
	}
	bflb_gpio_init(gpio,_sda, GPIO_INPUT);
	/* 读出该地址的数据 */
	for(i=0;i<8;i++) 
	{
		temp=temp>>1;
		
		if(bflb_gpio_read(gpio, _sda)) 
			temp|= 0x80;
		else 
			temp&=0x7F;
		bflb_gpio_set(gpio, _scl); 
		bflb_mtimer_delay_us(5);
		bflb_gpio_reset(gpio, _scl); 
		bflb_mtimer_delay_us(5);
	}
	
	bflb_gpio_reset(gpio, _rst); 
	return temp;
}



/*********************************************************/
// 向DS1302写入一字节数据
/*********************************************************/
void DS1302_Write_Byte(uint8_t addr, uint8_t dat)
{
	uint8_t i;
	bflb_gpio_set(gpio, _rst);
	bflb_gpio_init(gpio,_sda, GPIO_OUTPUT);
	/* 写入目标地址：addr*/
	for(i=0;i<8;i++) 
	{ 
		if(addr&0x01) 
			bflb_gpio_set(gpio, _sda); 
		else 
			bflb_gpio_reset(gpio, _sda);

		bflb_gpio_set(gpio, _scl); // SCK脚置低
		bflb_mtimer_delay_us(5);
		bflb_gpio_reset(gpio, _scl); // SCK脚置低
		bflb_mtimer_delay_us(5);
		
		addr=addr>>1;
	}
	
	/* 写入数据：dat*/
	for(i=0;i<8;i++) 
	{
		if(dat&0x01) 
			bflb_gpio_set(gpio, _sda); 
		else 
			bflb_gpio_reset(gpio, _sda);
	
		bflb_gpio_set(gpio, _scl);
		bflb_mtimer_delay_us(5);
		bflb_gpio_reset(gpio, _scl); 
		bflb_mtimer_delay_us(5);
		
		dat=dat>>1;
	}
	bflb_gpio_reset(gpio, _rst);				
}






/*********************************************************/
// 初始化DS1302
/*********************************************************/
void DS1302_Init(uint8_t SCL,uint8_t SDA,uint8_t RST)
{
	_scl=SCL;
	_sda=SDA;
	_rst=RST;
	gpio = bflb_device_get_by_name("gpio");
    /* SDA */
    bflb_gpio_init(gpio, _sda, GPIO_INPUT);
    /* SCL */
    bflb_gpio_init(gpio, _scl, GPIO_OUTPUT );
	/* rst*/
	bflb_gpio_init(gpio, _rst, GPIO_OUTPUT);

	bflb_gpio_reset(gpio, _scl); // SCK脚置低
	bflb_gpio_reset(gpio, _rst); // RST脚置低				
}

/*********************************************************/
// 向DS1302写入时间数据
/*********************************************************/
void DS1302_Write_Time() 
{
  uint8_t i;
	uint8_t temp1;
	uint8_t temp2;
	
	for(i=0;i<7;i++)			// 十进制转BCD码
	{
		temp1=(TimeBuff[i]/10)<<4;
		temp2=TimeBuff[i]%10;
		TimeBuff[i]=temp1+temp2;
	}
	
	DS1302_Write_Byte(0x8E,0x00);								// 关闭写保护 
	DS1302_Write_Byte(0x80,0x80);								// 暂停时钟 
	DS1302_Write_Byte(0x8C,TimeBuff[0]);				// 年 
	DS1302_Write_Byte(0x88,TimeBuff[1]);				// 月 
	DS1302_Write_Byte(0x86,TimeBuff[2]);				// 日 
	DS1302_Write_Byte(0x8A,TimeBuff[3]);				// 星期
	DS1302_Write_Byte(0x84,TimeBuff[4]);				// 时 
	DS1302_Write_Byte(0x82,TimeBuff[5]);				// 分
	DS1302_Write_Byte(0x80,TimeBuff[6]);				// 秒
	DS1302_Write_Byte(0x80,TimeBuff[6]&0x7F);		// 运行时钟
	DS1302_Write_Byte(0x8E,0x80);								// 打开写保护  
}


/*********************************************************/
// 判断时钟芯片是否正在运行
/*********************************************************/
void DS1302_Start_or_Stop()
{
	if(DS1302_Read_Byte(0x81)>=128)			
	{
		DS1302_Write_Time();							// 如果没用，则初始化一个时间
	}
	
}

/*********************************************************/
// 从DS1302读出时间数据
/*********************************************************/
void DS1302_Read_Time()  
{ 
	uint8_t i;

	TimeBuff[0]=DS1302_Read_Byte(0x8D);						// 年 
	TimeBuff[1]=DS1302_Read_Byte(0x89);						// 月 
	TimeBuff[2]=DS1302_Read_Byte(0x87);						// 日 
	TimeBuff[3]=DS1302_Read_Byte(0x8B);						// 星期
	TimeBuff[4]=DS1302_Read_Byte(0x85);						// 时 
	TimeBuff[5]=DS1302_Read_Byte(0x83);						// 分 
	TimeBuff[6]=(DS1302_Read_Byte(0x81))&0x7F;		// 秒 
	
	// 检查前6位是否都是255
    uint8_t all_255 = 1;
    for (i = 0; i < 6; i++) {
        if (TimeBuff[i] != 255) {
            all_255 = 0; 
            break;
        }
    }
	
    // 如果前6位都是255，返回
    if (all_255) {
        for (i = 0; i < 7; i++) {
            TimeBuff[i] = 0;
        }
        return;
    }

	for(i=0;i<7;i++)		// BCD转十进制
	{           
		TimeBuff[i]=(TimeBuff[i]/16)*10+TimeBuff[i]%16;
	}
}
#endif