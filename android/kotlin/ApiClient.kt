package com.uniquesacco.devicecontrol.api

import okhttp3.ConnectionPool
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Protocol
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {

    // Persist/retrieve this from EncryptedSharedPreferences after Sanctum login.
    var authToken: String? = null

    private val authInterceptor = Interceptor { chain ->
        val request = chain.request().newBuilder().apply {
            authToken?.let { addHeader("Authorization", "Bearer $it") }
            addHeader("Accept", "application/json")
        }.build()
        chain.proceed(request)
    }

    // Command taps happen in bursts (toggle a relay, adjust a slider, check
    // status) — reusing one warm TCP+TLS connection instead of negotiating a
    // fresh one per request is the difference between a switch that feels
    // instant and one with a visible beat of lag on every tap.
    private val client = OkHttpClient.Builder()
        .addInterceptor(authInterceptor)
        .connectionPool(ConnectionPool(5, 5, TimeUnit.MINUTES))
        .protocols(listOf(Protocol.HTTP_2, Protocol.HTTP_1_1)) // falls back cleanly if the server doesn't speak h2
        .connectTimeout(5, TimeUnit.SECONDS)
        .readTimeout(8, TimeUnit.SECONDS)
        .writeTimeout(5, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    fun create(baseUrl: String): DeviceApi {
        return Retrofit.Builder()
            .baseUrl(baseUrl) // e.g. "https://control.uniquesacco.mw/"
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(DeviceApi::class.java)
    }
}
