cmake_minimum_required(VERSION 3.15)

include(proj.conf)

find_package(bouffalo_sdk REQUIRED HINTS $ENV{BL_SDK_BASE})

# 添加头文件搜索路径
include_directories(src/dev src/lib src/EPD src/EPD/utility src/config)

# 收集 src目录下的所有.c 文件
file(GLOB SRC_FILES "src/EPD/*.c" "src/lib/*.c" "src/EPD/utility/*.c")

# 将收集到的 .c 文件添加到目标中
target_sources(app PRIVATE ${SRC_FILES} ${UTILITY_FILES} ${lib_FILES})

sdk_set_main_file(main.c)

project(E-Paper)




