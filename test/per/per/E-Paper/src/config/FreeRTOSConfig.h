/* * FreeRTOS Kernel V10.2.1 

* Copyright (C) 2019 Amazon.com, Inc. or its affiliates.  All Rights Reserved. * 
* 允许任何获得本软件（以下简称“软件”）及其相关文档文件（以下简称“软件”）副本的人在不限
制的情况下处理该软件，包括但不仅限于使用、复制、修改、合并、发布、分许可、以及/或者出售
软件副本，并允许获得软件供应的人这样做，条件如下： * * 上面的版权声明和此许可声明应包含
在所有副本或软件的实质性部分中。 * * 软件提供“原样” ，不保证任何形式的质量，包括但不仅
限于 merchantability、适用性和 noninfringement。在任何情况下，作者或版权持有者均不对
任何索赔、损失或其他责任负责，无论是在合同、侵权或其他方面，由于、来自或与软件或使用或其
他交易软件有关。 * 
* http://www.FreeRTOS.org * 
* http://aws.amazon.com/freertos * 

* 1 个制表符等于 4 个空格！ 
*/

/*
 FreeRTOS 使用 FreeRTOSConfig.h 配置文件进行定制。 每个 FreeRTOS 应用程序必须在其预处理
 器的包含路径中包含 FreeRTOSConfig.h 头文件 。 FreeRTOSConfig.h 为正在构建的应用程序定制
 RTOS 内核 。因此它是特定于应用程序的，而非 RTOS，并且应当 位于应用程序目录，而不是 RTOS 
 内核源代码目录 。
 RTOS 源代码下载内容中的每个演示应用程序都有自己的 FreeRTOSConfig.h 文件。 一些演示版本比
 较旧，没有包含所有 可用的配置选项。 其中省略的配置选项 在 RTOS 源文件中被设置为默认值。
*/

#ifndef FREERTOS_CONFIG_H
#define FREERTOS_CONFIG_H

/*-----------------------------------------------------------
应用特定定义。
这些定义应根据您的特定硬件和应用需求进行调整。
这些参数在 FreeRTOS 网站上的 FreeRTOS API 文档的“配置”部分中描述。
请参阅 http://www.freertos.org/a00110.html。
 *----------------------------------------------------------*/
#include "stdio.h"

#ifdef BL702
#define configMTIME_BASE_ADDRESS    (0x02000000UL + 0xBFF8UL)  // MTIME寄存器基地址
#define configMTIMECMP_BASE_ADDRESS (0x02000000UL + 0x4000UL)  // MTIMECMP寄存器基地址
#else
#define configMTIME_BASE_ADDRESS    (0xE0000000UL + 0xBFF8UL)  // MTIME寄存器基地址
#define configMTIMECMP_BASE_ADDRESS (0xE0000000UL + 0x4000UL)  /*MTIMECMP寄存器基地址*/ 
#endif

#define configSUPPORT_STATIC_ALLOCATION         1  // 是否支持静态内存分配
#define configUSE_PREEMPTION                    1  // 是否使用抢占式调度
#define configUSE_IDLE_HOOK                     0  // 是否使用空闲钩子函数
#define configUSE_TICK_HOOK                     0  // 是否使用时钟节拍钩子函数
#define configCPU_CLOCK_HZ                      ((uint32_t)(1 * 1000 * 1000))   // CPU时钟频率
#define configTICK_RATE_HZ                      ((TickType_t)1000)  // 时钟节拍频率
#define configMAX_PRIORITIES                    (32)  // 任务的最大优先级数
#define configMINIMAL_STACK_SIZE                ((unsigned short)128) // 任务栈的最小大小 /* Only needs to be this high as some demo tasks also use this constant.  In production only the idle task would use this. */
#define configTOTAL_HEAP_SIZE                   ((size_t)100 * 1024)  // 堆内存总大小
#define configMAX_TASK_NAME_LEN                 (16)  // 任务名称的最大长度
#define configUSE_TRACE_FACILITY                1  // 是否使用追踪功能
#define configUSE_STATS_FORMATTING_FUNCTIONS    1  // 是否使用统计数据格式化功能
#define configUSE_16_BIT_TICKS                  0  // 是否时节计器
#define configIDLE_SHOULD_YIELD                 0   // 空闲任务是否具有低优先级的任务也处于就绪状态时主动让出
#define configUSE_MUTEXES                       1   // 是否使用互斥锁
#define configQUEUE_REGISTRY_SIZE               8  // 队列注册表的大小
#define configCHECK_FOR_STACK_OVERFLOW          2  // 是否检测任务栈溢出
#define configUSE_RECURSIVE_MUTEXES             1  // 是否使用递归互斥锁
#define configUSE_MALLOC_FAILED_HOOK            1  // 是否使用内存分配失败钩子函数
#define configUSE_APPLICATION_TASK_TAG          1  // 是否使用任务标签
#define configUSE_COUNTING_SEMAPHORES           1  // 是否使用计数信号量
#define configGENERATE_RUN_TIME_STATS           0  // 是否生成运行时统计信息
#define configUSE_PORT_OPTIMISED_TASK_SELECTION 1  // 是否使用优化的任务选择算法
#define configUSE_TICKLESS_IDLE                 0  // 是否使用低功耗模式
#define configUSE_POSIX_ERRNO                   1  // 是否使用POSIX风格的错误码

/* 协程定义. */
#define configUSE_CO_ROUTINES           0    // 是否使用协程
#define configMAX_CO_ROUTINE_PRIORITIES (2)  // 协程的最大优先级数


/* 软件定时器定义 . */
#define configUSE_TIMERS             1  // 是否使用软件定时器
#define configTIMER_TASK_PRIORITY    (configMAX_PRIORITIES - 1)  // 定时器任务的优先级
#define configTIMER_QUEUE_LENGTH     4   // 定时器队列的长度
#define configTIMER_TASK_STACK_DEPTH (1024)  // 定时器任务的栈深度

/* 任务优先级. */
#ifndef uartPRIMARY_PRIORITY
#define uartPRIMARY_PRIORITY (configMAX_PRIORITIES - 3)  /*UART任务的优先级*/ 
#endif


/**
 * 启用 "INCLUDE" 的宏，允许在应用程序构建过程中，不包含未使用的实时内核组件 。这确保 RTOS 不会使用任何超过特定嵌入式应用程序所需的 ROM 或 RAM 。
 * 每个宏都具备如下形式：

 * INCLUDE_FunctionName
 * 其中 FunctionName 表示可以选择排除的 API 函数（或函数集合） 。要包含某个 API 函数，请将对应宏设置为 1， 或设置为 0，排除该函数。例如，要包含 vTaskDelete() API 函数，请使用：
 * #define INCLUDE_vTaskDelete    1
 * 要将 vTaskDelete() 从构建中排除，请使用：
#define INCLUDE_vTaskDelete    0
*/
#define INCLUDE_vTaskPrioritySet         1  
#define INCLUDE_uxTaskPriorityGet        1  
#define INCLUDE_vTaskDelete              1  
#define INCLUDE_vTaskCleanUpResources    1  
#define INCLUDE_vTaskSuspend             1   
#define INCLUDE_vTaskDelayUntil          1
#define INCLUDE_vTaskDelay               1
#define INCLUDE_eTaskGetState            1
#define INCLUDE_xTimerPendFunctionCall   1
#define INCLUDE_xTaskAbortDelay          1
#define INCLUDE_xTaskGetHandle           1
#define INCLUDE_xSemaphoreGetMutexHolder 1

/* 无assert.h头文件的正常assert()语义. */
void vApplicationMallocFailedHook(void);  // 内存分配失败钩子函数
void vAssertCalled(void);  // 断言函数
#define configASSERT(x)                        \
    if ((x) == 0) {                            \
        printf("file [%s]\r\n", __FILE__);     \
        printf("func [%s]\r\n", __FUNCTION__); \
        printf("line [%d]\r\n", __LINE__);     \
        printf("%s\r\n", (const char *)(#x));  \
        vAssertCalled();                       \
    }

#if (configUSE_TICKLESS_IDLE != 0)
void vApplicationSleep(uint32_t xExpectedIdleTime);  // 低功耗模式下的睡眠函数
#define portSUPPRESS_TICKS_AND_SLEEP(xExpectedIdleTime) vApplicationSleep(xExpectedIdleTime)
#endif

// #define portUSING_MPU_WRAPPERS

#endif /* FREERTOS_CONFIG_H */
